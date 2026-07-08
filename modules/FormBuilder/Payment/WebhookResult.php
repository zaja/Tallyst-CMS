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
        /** True only for a confirmed FULL refund (partial refunds are ignored — v1 = full only). */
        public readonly bool $isRefund = false,
        /**
         * Our internal Order id, echoed back by the provider from the checkout metadata. Additive:
         * Stripe/PayPal leave it null and OrderPaymentSync keeps matching them by session/payment-intent;
         * Dodo (MoR) carries it (metadata.order_id) as its primary correlation key, since the Dodo
         * webhook does not reliably carry the checkout session id. Kept uniform so future Dodo event
         * types (e.g. Phase 2 licence/entitlement events) resolve the order the same way.
         */
        public readonly ?string $orderId = null,
    ) {
    }

    public function isCheckoutCompleted(): bool
    {
        return 'checkout.session.completed' === $this->eventType;
    }
}
