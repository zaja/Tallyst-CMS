<?php

namespace Tallyst\FormBuilder\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Workflow\WorkflowInterface;
use Tallyst\FormBuilder\Message\FulfillOrderMessage;
use Tallyst\FormBuilder\Payment\PaymentProcessorRegistry;
use Tallyst\FormBuilder\Repository\OrderRepository;

/**
 * Stripe webhook — the SOLE source of truth for "paid". Public, two-segment path
 * (outside /{slug} and /admin), no CSRF (it's a server-to-server call verified by
 * signature instead).
 *
 * Contract:
 *  - reject unverified payloads (400);
 *  - act only on checkout.session.completed with payment_status = paid;
 *  - mark the order paid synchronously (fast) then dispatch async fulfillment;
 *  - be idempotent (duplicate events are a no-op);
 *  - ack (200) unknown sessions so Stripe doesn't enter a retry loop.
 */
class StripeWebhookController extends AbstractController
{
    public function __construct(
        private readonly PaymentProcessorRegistry $payments,
        private readonly OrderRepository $orders,
        private readonly WorkflowInterface $orderStateMachine,
        private readonly MessageBusInterface $bus,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/webhook/stripe', name: 'form_builder_webhook_stripe', methods: ['POST'])]
    public function handle(Request $request): Response
    {
        try {
            $result = $this->payments->get('stripe')->parseSignedWebhook(
                $request->getContent(),
                (string) $request->headers->get('Stripe-Signature', ''),
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Stripe webhook signature verification failed.', ['error' => $e->getMessage()]);

            return new Response('Invalid signature', Response::HTTP_BAD_REQUEST);
        }

        // Only completed checkout sessions matter here; ack everything else.
        if (!$result->isCheckoutCompleted()) {
            return new Response('Ignored', Response::HTTP_OK);
        }

        // Guard: a session can "complete" while still unpaid (async payment methods).
        if (!$result->isPaid) {
            $this->logger->info('Stripe checkout completed but not paid.', ['session' => $result->sessionId]);

            return new Response('Not paid', Response::HTTP_OK);
        }

        if (null === $result->sessionId) {
            return new Response('No session id', Response::HTTP_OK);
        }

        $order = $this->orders->findOneByProviderSessionId($result->sessionId);
        if (null === $order) {
            // Unknown session (e.g. another integration) — ack so Stripe stops retrying.
            $this->logger->info('Stripe webhook for unknown session.', ['session' => $result->sessionId]);

            return new Response('Unknown order', Response::HTTP_OK);
        }

        // Idempotent: duplicate delivery, or thank-you race already handled — no-op.
        if ($order->isPaid()) {
            return new Response('Already processed', Response::HTTP_OK);
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

        return new Response('OK', Response::HTTP_OK);
    }
}
