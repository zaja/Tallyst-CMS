<?php

namespace App\Tests\FormBuilder;

use PHPUnit\Framework\TestCase;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\FormType;

/**
 * Faza 6 K1: MoR sellable units — the MoR pandan of price variants. A unit carries a provider UNIT ID
 * (not a price; the provider charges). setMorUnits/hasMorUnits/morUnitAt mirror the variants API;
 * sellableUnits() is the read path (explicit list, else a single synthesized unit from the legacy
 * dodoProductId — which is EXACTLY what the K1 data-backfill stores, so this locks that mapping too).
 * Pure unit test — nobody consumes morUnits yet, so this only pins the model.
 */
class FormMorUnitsTest extends TestCase
{
    private function mor(): FormDefinition
    {
        return (new FormDefinition())->setFormType(FormType::DIGITAL_MOR)->setMorProvider('dodo');
    }

    public function testHasMorUnits(): void
    {
        self::assertFalse($this->mor()->hasMorUnits());
        self::assertTrue($this->mor()->setMorUnits([['label' => 'Personal', 'unitId' => 'pdt_a']])->hasMorUnits());
        // A row missing the unit id is NOT a complete unit.
        self::assertFalse($this->mor()->setMorUnits([['label' => 'Personal', 'unitId' => '']])->hasMorUnits());
    }

    public function testSetMorUnitsNormalizes(): void
    {
        $form = $this->mor()->setMorUnits([
            ['label' => 'Personal', 'unitId' => 'pdt_a', 'priceMinor' => '4900', 'currency' => 'eur'], // string price → int
            ['label' => '', 'unitId' => ''],                                     // fully-empty row → dropped
            ['label' => 'Team', 'unitId' => 'pdt_b'],                            // no price/currency → null cache
        ]);

        $units = $form->getMorUnits();
        self::assertCount(2, $units);
        self::assertSame(['label' => 'Personal', 'unitId' => 'pdt_a', 'priceMinor' => 4900, 'currency' => 'eur'], $units[0]);
        self::assertSame(['label' => 'Team', 'unitId' => 'pdt_b', 'priceMinor' => null, 'currency' => null], $units[1]);
    }

    public function testEmptyMorUnitsAreNull(): void
    {
        self::assertNull($this->mor()->setMorUnits([])->getMorUnits());
        self::assertNull($this->mor()->setMorUnits([['label' => '', 'unitId' => '']])->getMorUnits());
    }

    public function testMorUnitAtIsTheServerSideGate(): void
    {
        $form = $this->mor()->setMorUnits([
            ['label' => 'Personal', 'unitId' => 'pdt_a', 'priceMinor' => 2900, 'currency' => 'eur'],
            ['label' => 'Team', 'unitId' => 'pdt_b', 'priceMinor' => 4900, 'currency' => 'eur'],
        ]);

        self::assertSame('pdt_b', $form->morUnitAt(1)['unitId']);
        self::assertSame('Team', $form->morUnitAt(1)['label']);
        self::assertNull($form->morUnitAt(2), 'out-of-range index → rejected');
        self::assertNull($form->morUnitAt(-1), 'negative index → rejected');
    }

    public function testSellableUnitsFromExplicitList(): void
    {
        $form = $this->mor()->setMorUnits([
            ['label' => 'Personal', 'unitId' => 'pdt_a', 'priceMinor' => 2900, 'currency' => 'eur'],
            ['label' => 'Team', 'unitId' => 'pdt_b', 'priceMinor' => null, 'currency' => null],
        ]);

        $units = $form->sellableUnits();
        self::assertCount(2, $units);
        self::assertSame('pdt_a', $units[0]['unitId']);
        self::assertSame(2900, $units[0]['priceMinor']);
        self::assertNull($units[1]['priceMinor'], 'a null display-cache price stays null');
    }

    public function testSellableUnitsFallsBackToLegacyDodoProductId(): void
    {
        // No morUnits, but a legacy single dodoProductId → ONE synthesized unit (label cosmetic). This is
        // exactly the shape the K1 backfill writes, so an un-migrated form behaves as a one-entry list.
        $form = $this->mor()->setDodoProductId('pdt_legacy')->setPriceMinor(4900)->setCurrency('eur');

        $units = $form->sellableUnits();
        self::assertCount(1, $units);
        self::assertSame('pdt_legacy', $units[0]['unitId']);
        self::assertSame(4900, $units[0]['priceMinor']);
        self::assertSame('eur', $units[0]['currency']);
        self::assertSame('', $units[0]['label'], 'the fallback label is cosmetic (a single unit shows no choice)');
    }

    public function testExplicitListWinsOverLegacyFallback(): void
    {
        $form = $this->mor()
            ->setDodoProductId('pdt_legacy')->setPriceMinor(4900)
            ->setMorUnits([['label' => 'Personal', 'unitId' => 'pdt_new', 'priceMinor' => 1900, 'currency' => 'eur']]);

        $units = $form->sellableUnits();
        self::assertCount(1, $units);
        self::assertSame('pdt_new', $units[0]['unitId'], 'the explicit list is the source of truth, not dodoProductId');
    }

    public function testSellableUnitsEmptyWhenNothingLinked(): void
    {
        self::assertSame([], $this->mor()->sellableUnits());
    }

    public function testSellableUnitsIgnoresRowsWithoutUnitId(): void
    {
        // A half-filled row (label, no unit id) can't be sold; if it's the only one, fall back to the legacy id.
        $form = $this->mor()->setDodoProductId('pdt_legacy')->setPriceMinor(4900)->setCurrency('eur');
        // setMorUnits keeps the half-filled row (for validation), but sellableUnits skips it → fallback fires.
        $form->setMorUnits([['label' => 'Broken', 'unitId' => '']]);

        $units = $form->sellableUnits();
        self::assertCount(1, $units);
        self::assertSame('pdt_legacy', $units[0]['unitId']);
    }
}
