<?php

namespace Tallyst\FormBuilder\Entity;

/**
 * The EXPLICIT, remembered "what is this form" decision (Faza 4), replacing the old guessing (isProduct
 * derived from price/variants, isMerchantOfRecordForm derived from the Dodo product / payment methods).
 *
 * Four leaves — physical is always self-billed (a physical product can't go through a Merchant-of-Record),
 * so there is deliberately no `physical_mor`:
 *   - MESSAGES     — receives messages (a free contact/enquiry form);
 *   - PHYSICAL     — sells a physical product, the seller collects tax (Stripe/PayPal), has shipping;
 *   - DIGITAL      — sells a digital product / service, the seller collects tax, no shipping;
 *   - DIGITAL_MOR  — sells a digital product / service through a Merchant-of-Record (Dodo).
 *
 * The two booleans the rest of the code reads derive FROM this (see isProduct()/isMerchantOfRecord()).
 * ⚠ In KOMAD 1 this is STORED but not yet consumed — isProduct()/isMerchantOfRecordForm() still guess.
 * Wiring the consumers to read this is KOMAD 2. See PLAN-FAZA-4-WIZARD.md §2.
 */
enum FormType: string
{
    case MESSAGES = 'messages';
    case PHYSICAL = 'physical';
    case DIGITAL = 'digital';
    case DIGITAL_MOR = 'digital_mor';

    /** A product form (sells something) — everything except a message form. Maps to isProduct(). */
    public function isProduct(): bool
    {
        return self::MESSAGES !== $this;
    }

    /** A Merchant-of-Record (Dodo) form — the MoR is the legal seller and owns tax. Maps to isMerchantOfRecordForm(). */
    public function isMerchantOfRecord(): bool
    {
        return self::DIGITAL_MOR === $this;
    }

    /** A physically-shipped product — the only type that offers Tallyst delivery/countries. */
    public function isPhysical(): bool
    {
        return self::PHYSICAL === $this;
    }
}
