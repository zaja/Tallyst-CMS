<?php

namespace App\Tests\FormBuilder;

use App\Settings\SettingsManager;
use PHPUnit\Framework\TestCase;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Service\TaxCalculator;
use Tallyst\FormBuilder\Service\TaxCatalog;

/**
 * Inclusive tax math (tax = gross - net, no cent drift — the formula is unchanged in Faza 3) + the per-form
 * rate resolution (forForm): the rate is picked BY KEY from the live catalog, so a percentage change in
 * Settings takes effect immediately; null key / deleted key fall back to the default; "no tax" and the
 * master-off both yield null.
 */
class TaxCalculatorTest extends TestCase
{
    /** Two rates: PDV 25% (default) + Reduced 13.5%. */
    private const RATES = '[{"key":"k1","name":"PDV","rate":"25","default":true},{"key":"k2","name":"Reduced","rate":"13.5","default":false}]';

    /**
     * @param array<string, mixed> $settings
     */
    private function calc(array $settings): TaxCalculator
    {
        $manager = $this->createStub(SettingsManager::class);
        $manager->method('get')->willReturnCallback(static fn (string $k) => $settings[$k] ?? null);

        return new TaxCalculator($manager, new TaxCatalog($manager));
    }

    private function form(?string $key): FormDefinition
    {
        return (new FormDefinition())->setTaxRateKey($key);
    }

    // --- math (unchanged formula, now with an explicit rate) ---

    public function testInclusiveSplit(): void
    {
        $b = $this->calc([])->breakdown(4900, 25.0);
        self::assertSame(3920, $b['net']);
        self::assertSame(980, $b['tax']);
        self::assertSame(4900, $b['net'] + $b['tax']);
    }

    public function testRoundingClosesOnOddAmount(): void
    {
        $b = $this->calc([])->breakdown(4999, 25.0);
        self::assertSame(4999, $b['net'] + $b['tax'], 'net + tax must always equal gross');
        self::assertSame(3999, $b['net']);
        self::assertSame(1000, $b['tax']);
    }

    public function testZeroRateMeansNoTax(): void
    {
        $b = $this->calc([])->breakdown(4900, 0.0);
        self::assertSame(4900, $b['net']);
        self::assertSame(0, $b['tax']);
    }

    public function testIsEnabled(): void
    {
        self::assertTrue($this->calc(['tax_enabled' => true])->isEnabled());
        self::assertFalse($this->calc([])->isEnabled());
    }

    // --- per-form resolution (forForm) ---

    public function testForFormResolvesByKey(): void
    {
        $eff = $this->calc(['tax_enabled' => true, 'tax_rates' => self::RATES])->forForm($this->form('k2'));
        self::assertSame(13.5, $eff['rate']);
        self::assertSame('Reduced', $eff['name']);
    }

    public function testForFormNullKeyFallsToDefault(): void
    {
        $eff = $this->calc(['tax_enabled' => true, 'tax_rates' => self::RATES])->forForm($this->form(null));
        self::assertSame(25.0, $eff['rate'], 'null key → the default rate');
        self::assertSame('PDV', $eff['name']);
    }

    public function testForFormDeletedKeyFallsToDefault(): void
    {
        // A form pointing at a key no longer in the catalog still taxes — at the default (not silently 0).
        $eff = $this->calc(['tax_enabled' => true, 'tax_rates' => self::RATES])->forForm($this->form('ghost'));
        self::assertSame(25.0, $eff['rate']);
        self::assertSame('PDV', $eff['name']);
    }

    public function testForFormNoTaxSentinelYieldsNull(): void
    {
        self::assertNull($this->calc(['tax_enabled' => true, 'tax_rates' => self::RATES])->forForm($this->form(FormDefinition::TAX_NONE)));
    }

    public function testForFormMasterOffYieldsNull(): void
    {
        self::assertNull($this->calc(['tax_enabled' => false, 'tax_rates' => self::RATES])->forForm($this->form('k1')));
    }

    public function testForFormLazyFallbackWhenCatalogUnsaved(): void
    {
        // No tax_rates saved → the catalog synthesizes the legacy default (PDV/25) → forForm still resolves.
        $eff = $this->calc(['tax_enabled' => true])->forForm($this->form(null));
        self::assertSame(25.0, $eff['rate']);
        self::assertSame('PDV', $eff['name']);
    }

    public function testForFormIsLiveByKey(): void
    {
        // Condition #1: the SAME key resolves to whatever the catalog rate CURRENTLY is — change PDV 25 → 27
        // in Settings and every form on k1 charges/shows 27 immediately, without touching the form.
        $before = $this->calc(['tax_enabled' => true, 'tax_rates' => self::RATES])->forForm($this->form('k1'));
        self::assertSame(25.0, $before['rate']);

        $raised = '[{"key":"k1","name":"PDV","rate":"27","default":true}]';
        $after = $this->calc(['tax_enabled' => true, 'tax_rates' => $raised])->forForm($this->form('k1'));
        self::assertSame(27.0, $after['rate'], 'a percentage change on the existing key is reflected live');
        self::assertSame('PDV', $after['name']);
    }
}
