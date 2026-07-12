<?php

namespace App\Tests\FormBuilder;

use PHPUnit\Framework\TestCase;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\FormType;

/**
 * Price variants (or-or): variants replace the fixed price, the chosen index is resolved server-side
 * (variantAt = the reject gate), and a product with no variants keeps the fixed-price behaviour.
 * Faza 4 KOMAD 2: isProduct() now reads the explicit formType (not price/variants) — the price→product
 * DERIVATION moved to FormTypeDeriver (its own test); variants mechanics (hasVariants/variantAt) are unchanged.
 */
class FormDefinitionVariantsTest extends TestCase
{
    private function withVariants(array $variants): FormDefinition
    {
        return (new FormDefinition())->setVariants($variants);
    }

    public function testHasVariants(): void
    {
        self::assertFalse((new FormDefinition())->hasVariants());
        self::assertTrue($this->withVariants([['label' => 'Pro', 'priceMinor' => 4900]])->hasVariants());
    }

    public function testIsProductReadsTheExplicitType(): void
    {
        // A product form is now decided by formType, regardless of a stray price/variant.
        self::assertTrue((new FormDefinition())->setFormType(FormType::DIGITAL)->isProduct());
        self::assertTrue((new FormDefinition())->setFormType(FormType::PHYSICAL)->isProduct());
        self::assertTrue((new FormDefinition())->setFormType(FormType::DIGITAL_MOR)->isProduct());
        // A messages form is never a product — even if it carries a price/variants.
        self::assertFalse((new FormDefinition())->setFormType(FormType::MESSAGES)->setPriceMinor(2900)->isProduct());
        self::assertFalse($this->withVariants([['label' => 'Pro', 'priceMinor' => 4900]])->isProduct(), 'default type MESSAGES → not a product');
    }

    public function testVariantAtIsTheServerSideGate(): void
    {
        $form = $this->withVariants([
            ['label' => 'Basic', 'priceMinor' => 2900],
            ['label' => 'Pro', 'priceMinor' => 4900],
        ]);

        self::assertSame(4900, $form->variantAt(1)['priceMinor']);
        self::assertSame('Pro', $form->variantAt(1)['label']);
        self::assertNull($form->variantAt(2), 'out-of-range index → rejected');
        self::assertNull($form->variantAt(-1), 'negative index → rejected');
    }

    public function testSetVariantsNormalizes(): void
    {
        $form = $this->withVariants([
            ['label' => 'Basic', 'priceMinor' => 2900],
            ['label' => '', 'priceMinor' => null],   // fully-empty row → dropped
            ['label' => 'Pro', 'priceMinor' => '4900'], // string price → coerced to int
        ]);

        $variants = $form->getVariants();
        self::assertCount(2, $variants);
        self::assertSame(['label' => 'Basic', 'priceMinor' => 2900], $variants[0]);
        self::assertSame(['label' => 'Pro', 'priceMinor' => 4900], $variants[1]);
    }

    public function testEmptyVariantsAreNull(): void
    {
        self::assertNull((new FormDefinition())->setVariants([])->getVariants());
        self::assertNull((new FormDefinition())->setVariants([['label' => '', 'priceMinor' => null]])->getVariants());
    }
}
