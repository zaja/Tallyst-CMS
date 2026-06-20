<?php

namespace Tallyst\FormBuilder\Payment;

/**
 * Provider-agnostic result of a verified payment webhook. Lets the webhook
 * controller stay free of any Stripe/PayPal specifics.
 */
final class WebhookResult
{
    public function __construct(
        public readonly string $eventType,
        public readonly ?string $sessionId,
        public readonly ?string $paymentIntentId,
        /** True only when the provider confirms the money was actually captured. */
        public readonly bool $isPaid,
        public readonly ?string $customerEmail,
    ) {
    }

    public function isCheckoutCompleted(): bool
    {
        return 'checkout.session.completed' === $this->eventType;
    }
}
