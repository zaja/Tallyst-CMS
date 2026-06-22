<?php

namespace Tallyst\FormBuilder\Payment;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Tallyst\FormBuilder\Entity\Order;

/**
 * Strategy for a payment provider. Stripe is the only implementation in pass 2a;
 * PayPal (2b) is a second implementation — no other code changes needed thanks to
 * the registry. Auto-tagged so the registry collects every implementation.
 */
#[AutoconfigureTag('app.payment_processor')]
interface PaymentProcessorInterface
{
    /** Machine name, matched against Order::getProvider() (e.g. "stripe"). */
    public function getName(): string;

    /**
     * Create a hosted checkout for the order and return the URL to redirect the
     * buyer to. Implementations set the provider session id on the order.
     */
    public function createCheckout(Order $order, string $successUrl, string $cancelUrl): string;

    /**
     * Verify the webhook signature and parse it into a provider-agnostic result.
     *
     * @throws \Throwable when the signature is invalid (caller returns 400)
     */
    public function parseSignedWebhook(string $payload, string $signatureHeader): WebhookResult;

    /**
     * Issue a FULL refund for the order's captured payment. Provider-agnostic — the processor
     * uses its own stored reference (Stripe: the payment intent). v1 supports full refunds only.
     *
     * @throws \Throwable on a provider error (caller catches → flash, never 500)
     */
    public function refund(Order $order): void;
}
