<?php

namespace Tallyst\FormBuilder\Payment;

use Stripe\StripeClient;
use Stripe\Webhook;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Tallyst\FormBuilder\Entity\Order;

/**
 * Stripe hosted Checkout. Amount is taken straight from the order's integer minor
 * units, so no float math happens here.
 */
class StripeProcessor implements PaymentProcessorInterface
{
    public function __construct(
        #[Autowire('%env(STRIPE_SECRET_KEY)%')]
        private readonly string $secretKey,
        #[Autowire('%env(STRIPE_WEBHOOK_SECRET)%')]
        private readonly string $webhookSecret,
    ) {
    }

    public function getName(): string
    {
        return 'stripe';
    }

    public function createCheckout(Order $order, string $successUrl, string $cancelUrl): string
    {
        $stripe = new StripeClient($this->secretKey);

        $session = $stripe->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $order->getCurrency(),
                    'unit_amount' => $order->getAmountMinor(),
                    'product_data' => ['name' => $order->getForm()?->getName() ?? 'Order'],
                ],
            ]],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) $order->getId(),
            'metadata' => ['order_id' => (string) $order->getId()],
        ]);

        $order->setProviderSessionId($session->id);

        return (string) $session->url;
    }

    public function refund(Order $order): void
    {
        $paymentIntentId = $order->getProviderPaymentIntentId();
        if (null === $paymentIntentId || '' === $paymentIntentId) {
            throw new \RuntimeException('Order has no payment intent to refund.');
        }

        // Full refund of the captured payment. Throws \Stripe\Exception\* on a provider error
        // (e.g. already refunded) — the caller catches it and shows a flash.
        (new StripeClient($this->secretKey))->refunds->create(['payment_intent' => $paymentIntentId]);
    }

    public function parseSignedWebhook(string $payload, string $signatureHeader): WebhookResult
    {
        // Throws \Stripe\Exception\SignatureVerificationException on a bad signature.
        $event = Webhook::constructEvent($payload, $signatureHeader, $this->webhookSecret);

        $object = $event->data->object ?? null;

        $sessionId = is_string($object->id ?? null) ? $object->id : null;
        $isPaid = 'paid' === ($object->payment_status ?? null);

        $intent = $object->payment_intent ?? null;
        $paymentIntentId = is_string($intent) ? $intent : (is_object($intent) ? ($intent->id ?? null) : null);

        $email = $object->customer_details->email ?? $object->customer_email ?? null;

        // charge.refunded: the object is the Charge (carries payment_intent). Flag a refund ONLY
        // when it's FULL (amount_refunded >= amount) — partial refunds are ignored (v1 = full only).
        $isRefund = false;
        if ('charge.refunded' === $event->type) {
            $amount = $object->amount ?? null;
            $refunded = $object->amount_refunded ?? null;
            $isRefund = is_int($amount) && is_int($refunded) && $refunded >= $amount && $amount > 0;
        }

        return new WebhookResult(
            eventType: $event->type,
            sessionId: $sessionId,
            paymentIntentId: $paymentIntentId,
            isPaid: $isPaid,
            customerEmail: is_string($email) ? $email : null,
            isRefund: $isRefund,
        );
    }
}
