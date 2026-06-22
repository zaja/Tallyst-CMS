<?php

namespace App\Tests\FormBuilder;

use PHPUnit\Framework\TestCase;
use Tallyst\FormBuilder\Entity\FormDefinition;

/**
 * Price variants (or-or): variants replace the fixed price, the chosen index is resolved server-side
 * (variantAt = the reject gate), and a product with no variants keeps the fixed-price behaviour.
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

    public function testIsProductCoversVariantsAndFixedPrice(): void
    {
        // Variants, no fixed price → product.
        self::assertTrue($this->withVariants([['label' => 'Pro', 'priceMinor' => 4900]])->isProduct());
        // Fixed price, no variants → product (unchanged).
        self::assertTrue((new FormDefinition())->setPriceMinor(2900)->isProduct());
        // Neither → not a product.
        self::assertFalse((new FormDefinition())->isProduct());
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
