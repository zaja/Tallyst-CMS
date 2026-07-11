<?php

namespace App\Tests\FormBuilder;

use App\Settings\SettingsManager;
use PHPUnit\Framework\TestCase;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Service\ShippingCatalog;

/**
 * The shipping catalog: a JSON-setting-backed named list {key, label, priceMinor}. Prices are minor
 * units (no float); keys are stable random (never positional). Save cleans raw editor rows.
 */
class ShippingCatalogTest extends TestCase
{
    /** In-memory ShippingCatalog over a stubbed SettingsManager (get/set round-trip). */
    private function catalog(?string $initialJson = null): ShippingCatalog
    {
        $store = [ShippingCatalog::SETTING_KEY => $initialJson];
        $manager = $this->createStub(SettingsManager::class);
        // Regular closures with by-ref $store (NOT arrow fns — those capture by value, so get() would
        // never see set()'s writes and the round-trip would break).
        $manager->method('get')->willReturnCallback(function (string $k) use (&$store) {
            return $store[$k] ?? null;
        });
        $manager->method('set')->willReturnCallback(function (string $k, $v) use (&$store): void {
            $store[$k] = $v;
        });

        return new ShippingCatalog($manager);
    }

    public function testEmptyWhenUnset(): void
    {
        self::assertSame([], $this->catalog()->all());
        self::assertSame([], $this->catalog('')->all());
        self::assertSame([], $this->catalog('not json')->all());
    }

    public function testAllParsesAndSkipsMalformed(): void
    {
        $json = json_encode([
            ['key' => 'aa11bb22', 'label' => 'Standard', 'priceMinor' => 500],
            ['key' => '', 'label' => 'No key', 'priceMinor' => 100],       // dropped (no key)
            ['key' => 'cc33dd44', 'label' => '', 'priceMinor' => 100],     // dropped (no label)
            ['key' => 'ee55ff66', 'label' => 'Express', 'priceMinor' => 1200],
        ]);

        $all = $this->catalog($json)->all();

        self::assertCount(2, $all);
        self::assertSame(['key' => 'aa11bb22', 'label' => 'Standard', 'priceMinor' => 500], $all[0]);
        self::assertSame('Express', $all[1]['label']);
    }

    public function testByKey(): void
    {
        $json = json_encode([['key' => 'aa11bb22', 'label' => 'Standard', 'priceMinor' => 500]]);
        $catalog = $this->catalog($json);

        self::assertSame('Standard', $catalog->byKey('aa11bb22')['label']);
        self::assertNull($catalog->byKey('missing'));
    }

    public function testSavePreservesKeysConvertsPriceTrimsLabelAndKeepsOrder(): void
    {
        $catalog = $this->catalog();
        $catalog->save([
            ['key' => 'keep0001', 'label' => '  Standard  ', 'price' => '5'],
            ['key' => 'keep0002', 'label' => 'Express', 'price' => '12.50'],
        ]);

        $all = $catalog->all();
        self::assertSame(['key' => 'keep0001', 'label' => 'Standard', 'priceMinor' => 500], $all[0]);
        self::assertSame(['key' => 'keep0002', 'label' => 'Express', 'priceMinor' => 1250], $all[1]);
    }

    public function testSaveGeneratesStableKeyWhenMissing(): void
    {
        $catalog = $this->catalog();
        $catalog->save([['label' => 'Standard', 'price' => '5']]);

        $key = $catalog->all()[0]['key'];
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $key, 'a fresh 8-hex random key is generated');
    }

    public function testSaveDropsEmptyLabelRowsButKeepsFreeDelivery(): void
    {
        $catalog = $this->catalog();
        $catalog->save([
            ['key' => '', 'label' => '', 'price' => ''],          // blank prototype leftover → dropped
            ['key' => 'free0001', 'label' => 'Pickup', 'price' => '0'], // free delivery → kept
        ]);

        $all = $catalog->all();
        self::assertCount(1, $all);
        self::assertSame('Pickup', $all[0]['label']);
        self::assertSame(0, $all[0]['priceMinor']);
    }

    public function testOfferedForIsSelectionIntersectCatalogInCatalogOrder(): void
    {
        $json = json_encode([
            ['key' => 'std00001', 'label' => 'Standard', 'priceMinor' => 500],
            ['key' => 'exp00002', 'label' => 'Express', 'priceMinor' => 1200],
            ['key' => 'pick0003', 'label' => 'Pickup', 'priceMinor' => 0],
        ]);
        $catalog = $this->catalog($json);

        // Selected out of order → result follows the CATALOG order (deterministic for index resolution).
        $form = (new FormDefinition())->setShippingMethods(['pick0003', 'std00001']);
        $offered = $catalog->offeredFor($form);

        self::assertCount(2, $offered);
        self::assertSame('std00001', $offered[0]['key'], 'catalog order, not selection order');
        self::assertSame('pick0003', $offered[1]['key']);
    }

    public function testOfferedForDropsDeletedMethodsAndEmptySelection(): void
    {
        $json = json_encode([['key' => 'std00001', 'label' => 'Standard', 'priceMinor' => 500]]);
        $catalog = $this->catalog($json);

        // A selected key whose method was deleted from the catalog is silently dropped.
        $form = (new FormDefinition())->setShippingMethods(['std00001', 'gone9999']);
        self::assertCount(1, $catalog->offeredFor($form));

        // No selection → nothing offered.
        self::assertSame([], $catalog->offeredFor(new FormDefinition()));
    }

    public function testSaveDeduplicatesKeys(): void
    {
        $catalog = $this->catalog();
        $catalog->save([
            ['key' => 'dup00001', 'label' => 'A', 'price' => '5'],
            ['key' => 'dup00001', 'label' => 'B', 'price' => '6'],
        ]);

        $all = $catalog->all();
        self::assertCount(2, $all);
        self::assertNotSame($all[0]['key'], $all[1]['key'], 'a duplicate key is regenerated');
    }
}
