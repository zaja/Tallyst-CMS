<?php

namespace Tallyst\FormBuilder\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\WorkflowInterface;
use Tallyst\FormBuilder\Entity\Order;
use Tallyst\FormBuilder\Message\FulfillOrderMessage;
use Tallyst\FormBuilder\Payment\WebhookResult;
use Tallyst\FormBuilder\Repository\DodoPendingLicenseRepository;
use Tallyst\FormBuilder\Repository\OrderRepository;

/**
 * Applies a verified, provider-agnostic WebhookResult to the order — the SINGLE place that holds the
 * paid + refund transitions and their idempotency guards. Both the Stripe and PayPal webhook
 * controllers call this AFTER their own (provider-specific) signature verification, so every provider
 * goes through identical guards and can't drift or bypass them.
 *
 * Invariants (the webhook is the SOLE source of truth for `paid`):
 *  - paid: only when the result confirms payment; idempotent (already-paid → no-op); mark paid
 *    synchronously, then dispatch async fulfilment (e-mails);
 *  - refund: full only; idempotent (already-refunded → no-op), so an admin-initiated refund (which
 *    applies + mails synchronously) isn't double-applied / double-mailed by the webhook that follows.
 */
class OrderPaymentSync
{
    public function __construct(
        private readonly OrderRepository $orders,
        #[Target('orderStateMachine')]
        private readonly WorkflowInterface $orderStateMachine,
        private readonly MessageBusInterface $bus,
        private readonly EntityManagerInterface $em,
        private readonly OrderMailer $orderMailer,
        private readonly LoggerInterface $logger,
        private readonly DodoPendingLicenseRepository $pendingLicenses,
    ) {
    }

    /** @return string a short status (for the webhook HTTP response / logs) */
    public function apply(WebhookResult $result): string
    {
        // Payment captured → mark the order paid (money truth).
        if ($result->isPaid) {
            // Correlation: an explicit order id (Dodo echoes metadata.order_id) wins; otherwise the
            // provider session id (Stripe/PayPal). Additive — Stripe/PayPal pass orderId=null and keep
            // matching by session id exactly as before.
            if (null !== $result->orderId) {
                $order = $this->orders->find((int) $result->orderId);
            } elseif (null !== $result->sessionId) {
                $order = $this->orders->findOneByProviderSessionId($result->sessionId);
            } else {
                return 'No correlation id';
            }

            if (null === $order) {
                // Unknown order (e.g. another integration) — ack so the provider stops retrying.
                $this->logger->info('Payment webhook for unknown order.', ['session' => $result->sessionId, 'order_id' => $result->orderId]);

                return 'Unknown order';
            }

            // Idempotent: duplicate delivery, or thank-you race already handled — no-op.
            if ($order->isPaid()) {
                return 'Already processed';
            }

            if ($result->paymentIntentId) {
                $order->setProviderPaymentIntentId($result->paymentIntentId);
            }
            if ($result->customerEmail) {
                $order->setCustomerEmail($result->customerEmail);
            }

            // Phase 2 passive capture (Dodo/MoR). All null for Stripe/PayPal → these are no-ops there.
            $this->captureProviderFields($order, $result);

            // The order now has its payment_id — claim a licence that arrived BEFORE this payment
            // (entitlement webhook won the race and parked it in the pending store, keyed by payment_id).
            $this->claimPendingLicense($order);

            // Money truth: mark paid synchronously (a fast DB write).
            if ($this->orderStateMachine->can($order, 'pay')) {
                $this->orderStateMachine->apply($order, 'pay');
            }
            $this->em->flush();

            // Fulfillment (e-mails) is a separate, retriable async step.
            $this->bus->dispatch(new FulfillOrderMessage((int) $order->getId()));

            return 'OK';
        }

        // Full refund (admin-initiated OR done in the provider dashboard) → flip to refunded.
        if ($result->isRefund) {
            // Same correlation rule as the paid branch: explicit order id (Dodo) wins, else the
            // payment-intent/capture id (Stripe/PayPal). Additive — Stripe/PayPal pass orderId=null.
            // NOTE: we deliberately do NOT fall back to sessionId here — for a Stripe charge.refunded
            // the result's sessionId is the CHARGE id, which would never match a stored session id.
            if (null !== $result->orderId) {
                $order = $this->orders->find((int) $result->orderId);
            } elseif (null !== $result->paymentIntentId) {
                $order = $this->orders->findOneByProviderPaymentIntentId($result->paymentIntentId);
            } else {
                return 'No payment intent';
            }

            if (null === $order) {
                return 'Unknown order';
            }

            if (Order::STATUS_REFUNDED === $order->getStatus()) {
                return 'Already refunded';
            }

            if ($this->orderStateMachine->can($order, 'refund')) {
                $this->orderStateMachine->apply($order, 'refund');
                $this->em->flush();
                $this->orderMailer->sendRefunded($order);
            }

            return 'OK';
        }

        // Anything else — ack so the provider doesn't retry.
        return 'Ignored';
    }

    /**
     * Attach the licence delivered by a Dodo entitlement_grant.created event. Passive capture only —
     * does NOT touch the order state machine (paid/refund are unaffected). Always resolves to a 200 for
     * the webhook (no retry): either it attaches now, or it parks the licence keyed by payment_id for
     * the paid webhook to claim (unordered/at-least-once delivery, so the entitlement can precede the
     * payment). Idempotent: set-if-null on the order, UNIQUE(payment_id) on the pending store.
     *
     * @return string a short status (for the webhook response / logs)
     */
    public function applyEntitlement(WebhookResult $result): string
    {
        $paymentId = $result->paymentIntentId;
        if (null === $paymentId || null === $result->licenseKey) {
            return 'Ignored';
        }

        $order = $this->orders->findOneByProviderPaymentIntentId($paymentId);
        if (null !== $order) {
            if (null === $order->getLicenseKey()) {
                $order->setLicenseKey($result->licenseKey);
                $this->em->flush();
            }

            return 'License attached';
        }

        // Order not found yet — the entitlement beat the payment. Park it; the paid branch claims it.
        $this->pendingLicenses->upsert($paymentId, $result->licenseKey);
        $this->em->flush();

        return 'License pending';
    }

    /** Passive capture of provider-reported fields (Dodo). Null fields (Stripe/PayPal) are skipped. */
    private function captureProviderFields(Order $order, WebhookResult $result): void
    {
        if (null !== $result->customerName) {
            $order->setCustomerName($result->customerName);
        }
        if (null !== $result->customerPhone) {
            $order->setCustomerPhone($result->customerPhone);
        }
        if (null !== $result->invoiceUrl) {
            $order->setInvoiceUrl($result->invoiceUrl);
        }
        if (null !== $result->dodoTaxMinor) {
            $order->setDodoTaxMinor($result->dodoTaxMinor);
        }
        if (null !== $result->dodoTotalMinor) {
            $order->setDodoTotalMinor($result->dodoTotalMinor);
        }
        if (null !== $result->dodoSettlementMinor) {
            $order->setDodoSettlementMinor($result->dodoSettlementMinor);
        }
        if (null !== $result->dodoSettlementCurrency) {
            $order->setDodoSettlementCurrency($result->dodoSettlementCurrency);
        }
    }

    /**
     * Claim a licence parked by an early entitlement webhook (keyed by the order's payment_id). Runs in
     * the paid branch AFTER the payment_id is set. Set-if-null on the order, then consume (remove) that
     * one pending row — both commit with the paid branch's own flush(). No orphan sweeping here (that
     * would share the money-truth flush + hydrate an unbounded query); see the ROADMAP backlog item.
     */
    private function claimPendingLicense(Order $order): void
    {
        $paymentId = $order->getProviderPaymentIntentId();
        if (null === $paymentId || '' === $paymentId || null !== $order->getLicenseKey()) {
            return;
        }

        $pending = $this->pendingLicenses->findByPaymentId($paymentId);
        if (null === $pending) {
            return;
        }

        $order->setLicenseKey($pending->getLicenseKey());
        $this->pendingLicenses->remove($pending);
    }
}
