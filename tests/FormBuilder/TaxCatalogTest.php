<?php

namespace App\Tests\FormBuilder;

use App\Settings\SettingsManager;
use PHPUnit\Framework\TestCase;
use Tallyst\FormBuilder\Service\TaxCatalog;

/**
 * The tax-rate catalog: a JSON-setting-backed named list {key, name, rate, default}. Rate is a clean
 * numeric string; keys are stable random. Backward-compat: an empty catalog LAZILY falls back to a single
 * default entry synthesized from the legacy tax_rate/tax_name scalars (or PDV/25).
 */
class TaxCatalogTest extends TestCase
{
    /**
     * @param array<string, ?string> $store initial settings (e.g. tax_rates, tax_rate, tax_name)
     */
    private function catalog(array $store = []): TaxCatalog
    {
        $manager = $this->createStub(SettingsManager::class);
        // Regular closures with by-ref $store (NOT arrow fns — those capture by value → get() wouldn't see set()).
        $manager->method('get')->willReturnCallback(function (string $k) use (&$store) {
            return $store[$k] ?? null;
        });
        $manager->method('set')->willReturnCallback(function (string $k, $v) use (&$store): void {
            $store[$k] = $v;
        });

        return new TaxCatalog($manager);
    }

    public function testLazyFallbackToPdv25WhenNothingConfigured(): void
    {
        $all = $this->catalog()->all();

        self::assertCount(1, $all);
        self::assertSame('PDV', $all[0]['name']);
        self::assertSame('25', $all[0]['rate']);
        self::assertTrue($all[0]['default']);
        self::assertSame(TaxCatalog::DEFAULT_KEY, $all[0]['key']);
    }

    public function testLazyFallbackUsesLegacyScalarSettings(): void
    {
        // An existing install that changed the global rate → the catalog default mirrors it (no migration).
        $catalog = $this->catalog(['tax_name' => 'VAT', 'tax_rate' => '20']);
        $default = $catalog->default();

        self::assertSame('VAT', $default['name']);
        self::assertSame('20', $default['rate']);
        self::assertTrue($default['default']);
    }

    public function testAllParsesSkipsMalformedAndGuaranteesOneDefault(): void
    {
        $json = json_encode([
            ['key' => 'aa11bb22', 'name' => 'PDV', 'rate' => '25'],
            ['key' => '', 'name' => 'No key', 'rate' => '5'],   // dropped (no key)
            ['key' => 'cc33dd44', 'name' => '', 'rate' => '5'], // dropped (no name)
            ['key' => 'ee55ff66', 'name' => 'Reduced', 'rate' => '13'],
        ]);
        $all = $this->catalog([TaxCatalog::SETTING_KEY => $json])->all();

        self::assertCount(2, $all);
        self::assertSame('PDV', $all[0]['name']);
        self::assertSame('Reduced', $all[1]['name']);
        // No row was marked default → the first becomes the default.
        self::assertTrue($all[0]['default']);
        self::assertFalse($all[1]['default']);
    }

    public function testByKeyAndDefaultHonourTheDefaultFlag(): void
    {
        $json = json_encode([
            ['key' => 'std00001', 'name' => 'PDV', 'rate' => '25', 'default' => false],
            ['key' => 'red00002', 'name' => 'Reduced', 'rate' => '13', 'default' => true],
        ]);
        $catalog = $this->catalog([TaxCatalog::SETTING_KEY => $json]);

        self::assertSame('Reduced', $catalog->default()['name'], 'the flagged entry is the default');
        self::assertSame('25', $catalog->byKey('std00001')['rate']);
        self::assertNull($catalog->byKey('missing'));
    }

    public function testSaveKeepsKeysNormalisesRateAndForcesOneDefault(): void
    {
        $catalog = $this->catalog();
        $catalog->save([
            ['key' => 'keep0001', 'name' => 'PDV', 'rate' => '25,00', 'default' => true],
            ['key' => 'keep0002', 'name' => 'Reduced', 'rate' => '13.5', 'default' => true], // second flag ignored
            ['key' => '', 'name' => '', 'rate' => ''], // blank prototype row → dropped
        ]);

        $all = $catalog->all();
        self::assertCount(2, $all);
        self::assertSame(['key' => 'keep0001', 'name' => 'PDV', 'rate' => '25', 'default' => true], $all[0]);
        self::assertSame('13.5', $all[1]['rate'], 'comma/decimal normalized');
        self::assertFalse($all[1]['default'], 'only the FIRST flagged row stays default');
    }

    public function testSaveGeneratesStableKeyWhenMissing(): void
    {
        $catalog = $this->catalog();
        $catalog->save([['name' => 'PDV', 'rate' => '25']]);

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $catalog->all()[0]['key']);
    }
}
