<?php

namespace App\Tests\FormBuilder;

use PHPUnit\Framework\TestCase;
use Tallyst\FormBuilder\Entity\FormDefinition;

/**
 * The per-form shipping-country allow-list (Faza 2): stored as UPPERCASE ISO alpha-2 codes (invalid
 * dropped), empty = ships everywhere.
 */
class FormDefinitionShippingCountriesTest extends TestCase
{
    public function testEmptyMeansShipsEverywhere(): void
    {
        $form = new FormDefinition();

        self::assertNull($form->getAllowedShippingCountries());
        self::assertTrue($form->allowsCountry('HR'));
        self::assertTrue($form->allowsCountry('US'), 'no allow-list → any country allowed');
    }

    public function testSetterNormalisesAndDropsInvalidCodes(): void
    {
        $form = (new FormDefinition())->setAllowedShippingCountries(['hr', ' DE ', 'XX', '', 'hr', 'de']);

        // Uppercased, deduped, invalid ('XX', '') dropped, order preserved.
        self::assertSame(['HR', 'DE'], $form->getAllowedShippingCountries());
    }

    public function testRestrictedListGatesCountries(): void
    {
        $form = (new FormDefinition())->setAllowedShippingCountries(['HR', 'DE']);

        self::assertTrue($form->allowsCountry('HR'));
        self::assertTrue($form->allowsCountry('de'), 'case-insensitive');
        self::assertFalse($form->allowsCountry('US'), 'a country outside the list is blocked');
    }

    public function testAllInvalidCollapsesToNull(): void
    {
        $form = (new FormDefinition())->setAllowedShippingCountries(['XX', 'ZZ', '']);

        self::assertNull($form->getAllowedShippingCountries());
        self::assertTrue($form->allowsCountry('US'), 'no valid codes → treated as no gate');
    }
}
