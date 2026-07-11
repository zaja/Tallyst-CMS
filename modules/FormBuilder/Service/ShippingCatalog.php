<?php

namespace Tallyst\FormBuilder\Service;

use App\Settings\SettingsManager;
use Tallyst\FormBuilder\Entity\FormDefinition;

/**
 * The ONE source for the shipping-method catalog — a named list {key, label, priceMinor} stored as a
 * single JSON setting (`shipping_methods`), NOT a table. Same "one service owns the setting" role that
 * TaxCalculator has for tax. Prices are INCLUSIVE (tax already in the amount), integer minor units —
 * never float.
 *
 * Keys are STABLE RANDOM per method (bin2hex(random_bytes(4))), generated on save and preserved across
 * edits — a form references methods by key (never by position), so reordering/renaming/deleting a method
 * can't silently remap another form's selection. See PLAN-FAZA-1-DOSTAVA.md §3.
 */
class ShippingCatalog
{
    public const SETTING_KEY = 'shipping_methods';

    public function __construct(private readonly SettingsManager $settings)
    {
    }

    /**
     * The configured methods, in order. Malformed rows (no key/label) are skipped so a keyless or
     * nameless method never surfaces.
     *
     * @return list<array{key: string, label: string, priceMinor: int}>
     */
    public function all(): array
    {
        $raw = $this->settings->get(self::SETTING_KEY);
        if (!is_string($raw) || '' === $raw) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $methods = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = (string) ($row['key'] ?? '');
            $label = trim((string) ($row['label'] ?? ''));
            if ('' === $key || '' === $label) {
                continue;
            }
            $methods[] = [
                'key' => $key,
                'label' => $label,
                'priceMinor' => max(0, (int) ($row['priceMinor'] ?? 0)),
            ];
        }

        return $methods;
    }

    /**
     * One method by its stable key, or null if it no longer exists (deleted from the catalog).
     *
     * @return array{key: string, label: string, priceMinor: int}|null
     */
    public function byKey(string $key): ?array
    {
        foreach ($this->all() as $method) {
            if ($method['key'] === $key) {
                return $method;
            }
        }

        return null;
    }

    /**
     * The methods a form actually offers at checkout = its selected keys ∩ the LIVE catalog, in the
     * CATALOG's order, dropping any key whose method was deleted. Both the front render and the checkout
     * index-resolution use THIS single list, so their ordering can never disagree. Empty selection (or all
     * selected methods deleted) → [].
     *
     * @return list<array{key: string, label: string, priceMinor: int}>
     */
    public function offeredFor(FormDefinition $form): array
    {
        $selected = $form->getShippingMethods() ?? [];
        if ([] === $selected) {
            return [];
        }

        $wanted = array_flip($selected);

        return array_values(array_filter(
            $this->all(),
            static fn (array $method): bool => isset($wanted[$method['key']]),
        ));
    }

    /**
     * Persist the catalog from raw editor rows ([{key?, label, price}], price in MAJOR units). Empty-label
     * rows are dropped (blank prototype leftovers); a missing/blank/duplicate key gets a fresh stable random
     * one; the price is converted major→minor with (int) round(...) — never a bare float cast — clamped ≥ 0
     * (0 = free delivery is valid).
     *
     * @param array<int|string, mixed> $rows
     */
    public function save(array $rows): void
    {
        $methods = [];
        $seenKeys = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            if ('' === $label) {
                continue; // no name → not a method (drops the blank prototype row)
            }

            $key = trim((string) ($row['key'] ?? ''));
            if ('' === $key || isset($seenKeys[$key])) {
                $key = $this->freshKey($seenKeys);
            }
            $seenKeys[$key] = true;

            $price = $row['price'] ?? null;
            $priceMinor = (null === $price || '' === $price) ? 0 : (int) round(((float) $price) * 100);

            $methods[] = [
                'key' => $key,
                'label' => $label,
                'priceMinor' => max(0, $priceMinor),
            ];
        }

        $this->settings->set(self::SETTING_KEY, json_encode(array_values($methods)) ?: '[]');
    }

    /**
     * A stable random 8-hex key, unique within the set being built.
     *
     * @param array<string, true> $seenKeys
     */
    private function freshKey(array $seenKeys): string
    {
        do {
            $key = bin2hex(random_bytes(4));
        } while (isset($seenKeys[$key]));

        return $key;
    }
}
