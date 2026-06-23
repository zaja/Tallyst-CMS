<?php

namespace Tallyst\FormBuilder\Service;

use App\Settings\SettingsManager;

/**
 * The ONE place for the inclusive-tax math (read by the checkout submit to record, and the form
 * render to display). Inclusive = the price already INCLUDES tax; tax is derived backwards, so the
 * charged (gross) amount never changes. `tax = gross - net` so net + tax always sum back to gross
 * exactly (no cent drift).
 */
class TaxCalculator
{
    public function __construct(private readonly SettingsManager $settings)
    {
    }

    public function isEnabled(): bool
    {
        return (bool) $this->settings->get('tax_enabled');
    }

    public function rate(): float
    {
        return (float) str_replace(',', '.', (string) $this->settings->get('tax_rate'));
    }

    public function name(): string
    {
        return (string) ($this->settings->get('tax_name') ?: 'PDV');
    }

    /**
     * Split a gross (tax-inclusive) amount in minor units into net + tax.
     *
     * @return array{net: int, tax: int}
     */
    public function breakdown(int $grossMinor): array
    {
        $rate = $this->rate();
        if ($rate <= 0.0) {
            return ['net' => $grossMinor, 'tax' => 0];
        }

        $net = (int) round($grossMinor / (1 + $rate / 100));

        return ['net' => $net, 'tax' => $grossMinor - $net];
    }
}
