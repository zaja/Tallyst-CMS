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
    ) {
    }

    /** @return string a short status (for the webhook HTTP response / logs) */
    public function apply(WebhookResult $result): string
    {
        // Payment captured → mark the order paid (money truth).
        if ($result->isPaid) {
            if (null === $result->sessionId) {
                return 'No session id';
            }

            $order = $this->orders->findOneByProviderSessionId($result->sessionId);
            if (null === $order) {
                // Unknown session (e.g. another integration) — ack so the provider stops retrying.
                $this->logger->info('Payment webhook for unknown session.', ['session' => $result->sessionId]);

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
            if (null === $result->paymentIntentId) {
                return 'No payment intent';
            }

            $order = $this->orders->findOneByProviderPaymentIntentId($result->paymentIntentId);
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
}
