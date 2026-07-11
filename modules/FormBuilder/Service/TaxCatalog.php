<?php

namespace Tallyst\FormBuilder\Service;

use App\Settings\SettingsManager;

/**
 * The ONE source for the named tax-rate catalog (Faza 3) — a list {key, name, rate, default} stored as a
 * single JSON setting (`tax_rates`), NOT a table. Same "one service owns the setting" role as
 * ShippingCatalog. Rate is an inclusive percentage kept as a clean numeric STRING (e.g. "25", "13.5"); the
 * math still lives in TaxCalculator (unchanged). Keys are STABLE RANDOM per rate (bin2hex(random_bytes(4)))
 * — a form references a rate by key, so reordering/renaming can't silently remap another form's choice.
 *
 * BACKWARD-COMPAT (the crux): if the `tax_rates` setting is empty/unset, all() LAZILY synthesizes ONE
 * default entry from the LEGACY scalar settings (`tax_name`/`tax_rate`, or the PDV/25 defaults). So an
 * existing install keeps working identically with ZERO data migration — a form with no per-form choice
 * resolves to this default rate. The first save of the Tax tab writes `tax_rates` explicitly (seeded with
 * this synthesized default), after which it is authoritative and the legacy scalars are moot.
 * See PLAN-FAZA-3-POREZ.md §2–§3.
 */
class TaxCatalog
{
    public const SETTING_KEY = 'tax_rates';

    /** The fixed key of the lazily-synthesized default entry (persists across the first save). */
    public const DEFAULT_KEY = 'default';

    public function __construct(private readonly SettingsManager $settings)
    {
    }

    /**
     * The configured rates, in order, with EXACTLY ONE marked default. Malformed rows (no key/name) are
     * skipped. Empty/unset catalog → the lazy legacy-fallback entry.
     *
     * @return list<array{key: string, name: string, rate: string, default: bool}>
     */
    public function all(): array
    {
        $raw = $this->settings->get(self::SETTING_KEY);
        if (!is_string($raw) || '' === $raw) {
            return $this->legacyFallback();
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || [] === $decoded) {
            return $this->legacyFallback();
        }

        $rates = [];
        $sawDefault = false;
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = (string) ($row['key'] ?? '');
            $name = trim((string) ($row['name'] ?? ''));
            if ('' === $key || '' === $name) {
                continue;
            }
            $default = !$sawDefault && (bool) ($row['default'] ?? false);
            $sawDefault = $sawDefault || $default;
            $rates[] = [
                'key' => $key,
                'name' => $name,
                'rate' => $this->normalizeRate($row['rate'] ?? '0'),
                'default' => $default,
            ];
        }

        if ([] === $rates) {
            return $this->legacyFallback();
        }
        if (!$sawDefault) {
            $rates[0]['default'] = true; // never leave the catalog without a default
        }

        return $rates;
    }

    /**
     * One rate by its stable key, or null if it no longer exists (deleted from the catalog).
     *
     * @return array{key: string, name: string, rate: string, default: bool}|null
     */
    public function byKey(string $key): ?array
    {
        foreach ($this->all() as $rate) {
            if ($rate['key'] === $key) {
                return $rate;
            }
        }

        return null;
    }

    /**
     * The default rate — the entry marked default (or the first). Used when a form makes no explicit
     * per-form choice (taxRateKey null). Null only when the catalog is genuinely empty.
     *
     * @return array{key: string, name: string, rate: string, default: bool}|null
     */
    public function default(): ?array
    {
        $all = $this->all();
        foreach ($all as $rate) {
            if ($rate['default']) {
                return $rate;
            }
        }

        return $all[0] ?? null;
    }

    /**
     * Persist the catalog from raw editor rows ([{key?, name, rate, default?}]). Empty-name rows are
     * dropped; a missing/blank/duplicate key gets a fresh stable random one; the rate is normalized to a
     * clean numeric string; EXACTLY ONE entry ends up default (the first flagged, else the first row).
     *
     * @param array<int|string, mixed> $rows
     */
    public function save(array $rows): void
    {
        $rates = [];
        $seenKeys = [];
        $defaultIndex = null;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ('' === $name) {
                continue; // no name → not a rate (drops the blank prototype row)
            }

            $key = trim((string) ($row['key'] ?? ''));
            if ('' === $key || isset($seenKeys[$key])) {
                $key = $this->freshKey($seenKeys);
            }
            $seenKeys[$key] = true;

            if (null === $defaultIndex && (bool) ($row['default'] ?? false)) {
                $defaultIndex = \count($rates);
            }
            $rates[] = [
                'key' => $key,
                'name' => $name,
                'rate' => $this->normalizeRate($row['rate'] ?? '0'),
                'default' => false,
            ];
        }

        if ([] !== $rates) {
            $rates[$defaultIndex ?? 0]['default'] = true;
        }

        $this->settings->set(self::SETTING_KEY, json_encode(array_values($rates)) ?: '[]');
    }

    /** The lazily-synthesized single default entry from the legacy scalar settings (or PDV/25). */
    private function legacyFallback(): array
    {
        $name = trim((string) $this->settings->get('tax_name'));
        $rawRate = trim((string) $this->settings->get('tax_rate'));

        return [[
            'key' => self::DEFAULT_KEY,
            'name' => '' !== $name ? $name : 'PDV',
            'rate' => '' !== $rawRate ? $this->normalizeRate($rawRate) : '25',
            'default' => true,
        ]];
    }

    /** A percentage as a clean numeric string ("25", "13.5"); comma decimals accepted; junk → "0". */
    private function normalizeRate(mixed $rate): string
    {
        $value = str_replace(',', '.', trim((string) $rate));
        if ('' === $value || !is_numeric($value)) {
            return '0';
        }

        $clean = rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');

        return '' !== $clean ? $clean : '0';
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
