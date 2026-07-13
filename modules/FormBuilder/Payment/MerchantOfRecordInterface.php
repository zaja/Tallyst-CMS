<?php

namespace Tallyst\FormBuilder\Payment;

/**
 * A payment provider that is the legal Merchant-of-Record (MoR). The MoR is the seller of record — it
 * calculates, collects and remits sales tax / VAT / GST itself, and the admin receives the net. So for a
 * MoR order, Tallyst's own (inclusive) TaxCalculator MUST NOT be applied (it would double-count tax).
 *
 * ⚠ Faza 6 (Komad 2) — promoted from a MARKER to a FUNCTIONAL contract: a MoR provider now also exposes
 * how to LIST / FETCH / VET its "sellable units" (the generic pojam behind Dodo product_id, Paddle price_id,
 * Lemon Squeezy variant_id). This is "napravi mjesto, ne pogađaj": the builder picker, the refresh endpoint
 * and the per-unit save guard resolve the form's MoR provider and call THROUGH this interface — never
 * `instanceof DodoProcessor`. Purely additive: only DodoProcessor (+ the test FakeMoRProcessor) implement it;
 * Stripe/PayPal do NOT, so the default stays "not a Merchant-of-Record". Extending PaymentProcessorInterface
 * means a resolved MoR provider also has getName()/isConfigured()/… — a MoR provider IS a payment processor.
 * Adding a second MoR provider (Paddle/LS) = implement this interface; NO caller changes.
 */
interface MerchantOfRecordInterface extends PaymentProcessorInterface
{
    /**
     * The provider's sellable units offered in the builder picker — FIXED-price one-time units only
     * (recurring / usage-based / pay-what-you-want / archived are dropped). Generic shape (keyed by `id`):
     *   list<array{id: string, name: string, description: ?string, price: ?string, priceMinor: ?int, currency: ?string}>
     * Empty when the provider is unconfigured (no HTTP) or on error — never throws.
     *
     * @return list<array<string, mixed>>
     */
    public function listUnits(): array;

    /**
     * Fetch ONE sellable unit's live data by id (for the "refresh" button). Returns:
     *   - null                 → could NOT fetch (unconfigured / transient error),
     *   - ['found' => false]   → the unit no longer exists at the provider,
     *   - ['found' => true, name, description, priceMinor, currency, sellable, archived] → live data.
     *
     * @return array<string, mixed>|null
     */
    public function fetchUnit(string $id): ?array;

    /**
     * Verify ONE unit by id (the save-time guard for a manually-typed id). READ-ONLY:
     *   - true  → sellable (a fixed-price one-time unit, not pay-what-you-want),
     *   - false → NOT sellable (recurring / usage-based / pay-what-you-want) → the caller rejects the save,
     *   - null  → could NOT verify (unconfigured / not found / error) → the caller warns but allows.
     */
    public function isSellableUnit(string $id): ?bool;
}
