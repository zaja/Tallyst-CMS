<?php

namespace Tallyst\FormBuilder\Service;

use App\Settings\SettingsManager;
use Tallyst\FormBuilder\Entity\FormDefinition;

/**
 * The ONE place for the inclusive-tax math (read by the checkout submit to record, and the form
 * render to display). Inclusive = the price already INCLUDES tax; tax is derived backwards, so the
 * charged (gross) amount never changes. `tax = gross - net` so net + tax always sum back to gross
 * exactly (no cent drift).
 *
 * Faza 3: the RATE is resolved per-form from the named catalog (TaxCatalog) via forForm() — the checkout
 * and the front note both go through it, so a form references a rate BY KEY and a percentage change in
 * Settings → Tax takes effect immediately everywhere. The formula is unchanged; only the rate SOURCE moved
 * from the single global scalar to the per-form catalog key.
 */
class TaxCalculator
{
    public function __construct(
        private readonly SettingsManager $settings,
        private readonly TaxCatalog $catalog,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) $this->settings->get('tax_enabled');
    }

    /**
     * The effective tax for a SPECIFIC form, or null = no tax. Null when: the global master is off; the
     * form opted out (taxRateKey === TAX_NONE); or the catalog is genuinely empty. Otherwise resolves the
     * rate BY KEY from the LIVE catalog — so changing a rate's percentage in Settings immediately changes
     * every form on that key (both the checkout charge split and the front note). A null key (backfilled
     * default state) OR a DELETED key falls back to the catalog default — still taxes, never silently drops
     * tax (a deliberate choice, unlike shipping's offeredFor which drops deleted methods). MoR-neutral: the
     * caller applies the same !isMerchantOfRecord gate, so a Dodo order never reaches here.
     *
     * @return array{rate: float, name: string}|null
     */
    public function forForm(FormDefinition $form): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $key = $form->getTaxRateKey();
        if (FormDefinition::TAX_NONE === $key) {
            return null; // explicit per-form opt-out
        }

        $entry = null !== $key ? $this->catalog->byKey($key) : null;
        $entry ??= $this->catalog->default(); // null key OR deleted key → default
        if (null === $entry) {
            return null; // genuinely empty catalog
        }

        return ['rate' => (float) $entry['rate'], 'name' => $entry['name']];
    }

    /**
     * Split a gross (tax-inclusive) amount in minor units into net + tax at the given rate (%). The rate
     * is supplied by the caller (from forForm) — the FORMULA is unchanged from before.
     *
     * @return array{net: int, tax: int}
     */
    public function breakdown(int $grossMinor, float $rate): array
    {
        if ($rate <= 0.0) {
            return ['net' => $grossMinor, 'tax' => 0];
        }

        $net = (int) round($grossMinor / (1 + $rate / 100));

        return ['net' => $net, 'tax' => $grossMinor - $net];
    }
}
