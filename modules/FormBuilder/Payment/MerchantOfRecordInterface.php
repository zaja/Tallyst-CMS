<?php

namespace Tallyst\FormBuilder\Payment;

/**
 * Marker interface: a payment provider that is the legal Merchant-of-Record (MoR). The MoR is the
 * seller of record — it calculates, collects and remits sales tax / VAT / GST itself, and the admin
 * receives the net. So for a MoR order, Tallyst's own (inclusive) TaxCalculator MUST NOT be applied
 * (it would double-count tax).
 *
 * Purely additive: only DodoProcessor implements it; Stripe/PayPal do NOT, so the default is
 * "not a Merchant-of-Record". Callers detect it with `instanceof` — no new method on
 * PaymentProcessorInterface, so the provider API contract (semver) is unchanged.
 */
interface MerchantOfRecordInterface
{
}
