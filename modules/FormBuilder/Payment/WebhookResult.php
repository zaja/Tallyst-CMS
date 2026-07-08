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
        /**
         * Phase 2 — Dodo (MoR) passive capture. All null for Stripe/PayPal, so their construction is
         * unchanged and OrderPaymentSync's paid/refund path treats them as no-ops.
         */
        /** True for a Dodo entitlement_grant.created event (a licence delivery, NOT a paid/refund event). */
        public readonly bool $isEntitlement = false,
        /** Licence key from the entitlement event (data.license_key.key). */
        public readonly ?string $licenseKey = null,
        /** Buyer name / phone from payment.succeeded.data.customer. */
        public readonly ?string $customerName = null,
        public readonly ?string $customerPhone = null,
        /** Link to the Dodo-hosted invoice (data.invoice_url). */
        public readonly ?string $invoiceUrl = null,
        /** Provider-authoritative amounts (minor units) — Dodo is the seller of record. */
        public readonly ?int $dodoTaxMinor = null,
        public readonly ?int $dodoTotalMinor = null,
        public readonly ?int $dodoSettlementMinor = null,
        public readonly ?string $dodoSettlementCurrency = null,
    ) {
    }

    public function isCheckoutCompleted(): bool
    {
        return 'checkout.session.completed' === $this->eventType;
    }
}
