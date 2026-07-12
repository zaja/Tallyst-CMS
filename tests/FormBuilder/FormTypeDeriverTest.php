<?php

namespace App\Tests\FormBuilder;

use PHPUnit\Framework\TestCase;
use Tallyst\FormBuilder\Entity\FormType;
use Tallyst\FormBuilder\Service\FormTypeDeriver;

/**
 * Faza 4 Komad 1: the backfill mapping (a form's legacy shape → its explicit FormType). This is the ONE
 * rule the backfill migration runs, so it locks that a backfilled form reproduces today's guessed
 * behaviour EXACTLY (isProduct + isMerchantOfRecordForm) — PLAN-FAZA-4-WIZARD.md §3.
 */
class FormTypeDeriverTest extends TestCase
{
    public function testMessagesWhenNoPriceNoVariantsNoMoR(): void
    {
        self::assertSame(FormType::MESSAGES, FormTypeDeriver::derive(null, null, null, null, null));
        self::assertSame(FormType::MESSAGES, FormTypeDeriver::derive(0, [], null, [], []));
    }

    public function testDigitalWhenPricedWithoutShipping(): void
    {
        self::assertSame(FormType::DIGITAL, FormTypeDeriver::derive(2900, null, null, ['stripe'], null));
        self::assertSame(FormType::DIGITAL, FormTypeDeriver::derive(2900, null, null, ['stripe', 'paypal'], []));
    }

    public function testPhysicalWhenPricedWithShipping(): void
    {
        self::assertSame(FormType::PHYSICAL, FormTypeDeriver::derive(2900, null, null, ['stripe'], ['ship_a']));
    }

    public function testProductByVariantWithoutFixedPrice(): void
    {
        $variants = [['label' => 'Pro', 'priceMinor' => 4900]];
        self::assertSame(FormType::DIGITAL, FormTypeDeriver::derive(0, $variants, null, null, null));
        self::assertSame(FormType::PHYSICAL, FormTypeDeriver::derive(null, $variants, null, null, ['ship_a']));
    }

    public function testEmptyVariantRowIsNotAProduct(): void
    {
        // A blank/half-empty variant row doesn't make it a product (mirrors hasVariants()).
        self::assertSame(FormType::MESSAGES, FormTypeDeriver::derive(0, [['label' => '', 'priceMinor' => 0]], null, null, null));
        self::assertSame(FormType::MESSAGES, FormTypeDeriver::derive(null, [['label' => 'X', 'priceMinor' => 0]], null, null, null));
    }

    public function testMoRViaDodoProductId(): void
    {
        self::assertSame(FormType::DIGITAL_MOR, FormTypeDeriver::derive(2900, null, 'prod_1', null, null));
    }

    public function testMoRViaDodoInPaymentMethods(): void
    {
        self::assertSame(FormType::DIGITAL_MOR, FormTypeDeriver::derive(2900, null, null, ['dodo'], null));
    }

    public function testMoRWinsOverShippingAndVariants(): void
    {
        // MoR is checked FIRST — a Dodo form with leftover shipping/variants is still digital_mor.
        self::assertSame(FormType::DIGITAL_MOR, FormTypeDeriver::derive(2900, null, 'prod_1', null, ['ship_a']));
        self::assertSame(FormType::DIGITAL_MOR, FormTypeDeriver::derive(0, [['label' => 'Pro', 'priceMinor' => 4900]], null, ['dodo'], null));
    }

    public function testEmptyDodoProductIdIsNotMoR(): void
    {
        // An empty-string product id is not a link (guards a blank column).
        self::assertSame(FormType::DIGITAL, FormTypeDeriver::derive(2900, null, '', ['stripe'], null));
    }

    /**
     * The invariant behind backward-compat: the derived type's booleans equal what the shape guessed today.
     */
    public function testDerivedTypeReproducesGuessedBooleans(): void
    {
        // free
        self::assertFalse(FormTypeDeriver::derive(null, null, null, null, null)->isProduct());
        // priced Stripe (no shipping) → product, not MoR
        $digital = FormTypeDeriver::derive(2900, null, null, ['stripe'], null);
        self::assertTrue($digital->isProduct());
        self::assertFalse($digital->isMerchantOfRecord());
        // physical → product, not MoR
        self::assertTrue(FormTypeDeriver::derive(2900, null, null, ['stripe'], ['ship_a'])->isProduct());
        // Dodo → product AND MoR
        $mor = FormTypeDeriver::derive(2900, null, 'prod_1', null, null);
        self::assertTrue($mor->isProduct());
        self::assertTrue($mor->isMerchantOfRecord());
    }
}
