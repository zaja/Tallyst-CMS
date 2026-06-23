<?php

namespace Tallyst\FormBuilder\Payment;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Tallyst\FormBuilder\Entity\Order;

/**
 * Strategy for a payment provider (Stripe, PayPal). The registry collects every implementation;
 * order.provider routes checkout/refund/webhook to the right one. Every provider-specific bit
 * (Stripe's local HMAC vs PayPal's verify-API + OAuth + capture step + base host + mode) lives
 * INSIDE the implementation — Core and FormBuilder-general code touch only this interface +
 * WebhookResult. Auto-tagged so the registry collects each implementation.
 */
#[AutoconfigureTag('app.payment_processor')]
interface PaymentProcessorInterface
{
    /** Machine name, matched against Order::getProvider() (e.g. "stripe", "paypal"). */
    public function getName(): string;

    /** Whether this provider has credentials (settings ?: env) — drives provider choice + badge. */
    public function isConfigured(): bool;

    /** "test" | "live" | "unconfigured" (Stripe: key prefix; PayPal: explicit sandbox/live). */
    public function getMode(): string;

    /**
     * Create a hosted checkout for the order and return the URL to redirect the buyer to.
     * Implementations set the provider session id on the order.
     */
    public function createCheckout(Order $order, string $successUrl, string $cancelUrl): string;

    /**
     * Finalize when the buyer returns from the provider. Stripe Checkout auto-captures → no-op;
     * PayPal must capture the approved order here. MUST be idempotent and MUST NOT set `paid` —
     * the verified webhook stays the sole source of truth for `paid`.
     *
     * @throws \Throwable on a capture error (caller catches → graceful "processing" view, never 500)
     */
    public function finalizeReturn(Order $order): void;

    /**
     * Verify the webhook (signature/cert) and parse it into a provider-agnostic result. Gets the
     * FULL request header bag (lowercased keys) — Stripe reads `stripe-signature`, PayPal reads its
     * `paypal-*` set + calls the verify API.
     *
     * @param array<string, string> $headers
     *
     * @throws \Throwable when verification fails (caller returns 400)
     */
    public function parseSignedWebhook(string $payload, array $headers): WebhookResult;

    /**
     * Issue a FULL refund for the order's captured payment, using the processor's own stored
     * reference (Stripe: payment intent; PayPal: capture id). v1 = full refunds only.
     *
     * @throws \Throwable on a provider error (caller catches → flash, never 500)
     */
    public function refund(Order $order): void;

    /**
     * The webhook event types this provider's endpoint needs subscribed — shown in the Postavke
     * setup guide (single source).
     *
     * @return string[]
     */
    public function getWebhookEvents(): array;

    /**
     * Admin deep-link to this order's transaction in the provider dashboard (mode-aware), or null
     * when there's nothing to link to (no captured payment reference yet).
     */
    public function dashboardUrl(Order $order): ?string;
}
