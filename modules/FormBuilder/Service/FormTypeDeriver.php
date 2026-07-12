<?php

namespace Tallyst\FormBuilder\Service;

use Tallyst\FormBuilder\Entity\FormType;

/**
 * Derives a FormType from a form's LEGACY shape (price / variants / Dodo product / payment methods /
 * shipping) — the ONE place that encodes the Faza-4 backfill rule, so the migration and its test share
 * exactly the same logic (no drift). Pure + static: no DB, no state.
 *
 * Rule (order matters — MoR wins first), reproducing today's guessing so a backfilled form behaves
 * IDENTICALLY (isProduct + isMerchantOfRecordForm), see PLAN-FAZA-4-WIZARD.md §3:
 *   MoR (a linked Dodo product OR 'dodo' among the allowed methods) → DIGITAL_MOR
 *   else product (a positive fixed price OR a complete variant):
 *          has a shipping method → PHYSICAL
 *          else                  → DIGITAL
 *   else                         → MESSAGES
 *
 * Used by the one-time backfill migration; kept as a pure primitive so it's testable and reusable
 * (e.g. a future form import that must assign a type). It does NOT replace the guessing in KOMAD 1 —
 * isProduct()/isMerchantOfRecordForm() are untouched until KOMAD 2.
 */
final class FormTypeDeriver
{
    /**
     * @param array<int, mixed>|null  $variants              [{label, priceMinor}, …]
     * @param string[]|null           $allowedPaymentMethods provider names
     * @param string[]|null           $shippingMethods       catalog keys
     */
    public static function derive(
        ?int $priceMinor,
        ?array $variants,
        ?string $dodoProductId,
        ?array $allowedPaymentMethods,
        ?array $shippingMethods,
    ): FormType {
        if (self::isMerchantOfRecord($dodoProductId, $allowedPaymentMethods)) {
            return FormType::DIGITAL_MOR;
        }

        if (self::isProduct($priceMinor, $variants)) {
            return [] !== ($shippingMethods ?? []) ? FormType::PHYSICAL : FormType::DIGITAL;
        }

        return FormType::MESSAGES;
    }

    /** @param string[]|null $allowedPaymentMethods */
    private static function isMerchantOfRecord(?string $dodoProductId, ?array $allowedPaymentMethods): bool
    {
        if (null !== $dodoProductId && '' !== trim($dodoProductId)) {
            return true;
        }

        return in_array('dodo', $allowedPaymentMethods ?? [], true);
    }

    /** @param array<int, mixed>|null $variants  A product = a positive fixed price OR a complete variant. */
    private static function isProduct(?int $priceMinor, ?array $variants): bool
    {
        if ((int) $priceMinor > 0) {
            return true;
        }

        foreach ($variants ?? [] as $variant) {
            if (is_array($variant)
                && '' !== trim((string) ($variant['label'] ?? ''))
                && (int) ($variant['priceMinor'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }
}
