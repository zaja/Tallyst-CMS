<?php

namespace App\Tests\Settings;

use App\Icon\IconRegistry;
use App\Repository\MenuRepository;
use App\Settings\CoreSettingsProvider;
use App\Settings\SettingType;
use PHPUnit\Framework\TestCase;

/**
 * Locks the configurable-footer settings: 1–4 columns, one menu + one text field per column
 * (implicit type — the layout renders menu-else-text), and the old single footer_text/footer_menu
 * fields are gone. Render behaviour (menu precedence, name heading, empty-column skip) is a Twig
 * concern with no extractable PHP logic → covered by the user smoke.
 */
class FooterSettingsTest extends TestCase
{
    /** @return \App\Settings\SettingDefinition[] */
    private function footerSection(): array
    {
        $menus = $this->createStub(MenuRepository::class);
        $menus->method('findAll')->willReturn([]);
        $provider = new CoreSettingsProvider($menus, new IconRegistry());

        foreach ($provider->getSettingsSections() as $section) {
            if ('footer' === $section->key) {
                return $section->definitions;
            }
        }

        self::fail('footer settings section not found');
    }

    public function testColumnCountChoicesOneToFour(): void
    {
        $byKey = [];
        foreach ($this->footerSection() as $d) {
            $byKey[$d->key] = $d;
        }

        self::assertArrayHasKey('footer_columns', $byKey);
        self::assertSame(['1', '2', '3', '4'], array_values($byKey['footer_columns']->choices));
    }

    public function testPerColumnMenuAndTextFields(): void
    {
        $byKey = [];
        foreach ($this->footerSection() as $d) {
            $byKey[$d->key] = $d;
        }

        for ($n = 1; $n <= 4; ++$n) {
            self::assertArrayHasKey('footer_col'.$n.'_menu', $byKey);
            self::assertSame(SettingType::CHOICE, $byKey['footer_col'.$n.'_menu']->type);
            self::assertArrayHasKey('footer_col'.$n.'_text', $byKey);
            self::assertSame(SettingType::RICH_TEXT, $byKey['footer_col'.$n.'_text']->type);
        }
    }

    public function testOldSingleFieldsGoneButBrandingKept(): void
    {
        $keys = array_map(static fn ($d) => $d->key, $this->footerSection());

        // Replaced by the per-column model (no migration — abandoned, see the commit note).
        self::assertNotContains('footer_text', $keys);
        self::assertNotContains('footer_menu', $keys);
        // The branding line is untouched.
        self::assertContains('footer_copyright', $keys);
        self::assertContains('footer_show_powered_by', $keys);
    }
}
