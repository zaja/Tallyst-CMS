<?php

namespace App\Tests\Settings;

use App\Icon\IconRegistry;
use App\Repository\MenuRepository;
use App\Settings\CoreSettingsProvider;
use App\Settings\SettingType;
use App\Twig\TopBarExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Locks the top-bar settings: the social URL fields are generated from IconRegistry::brandKeys()
 * (single-sourced, not hardcoded), and the render-side filter shows an icon only for a set,
 * scheme-safe URL.
 */
class TopBarSettingsTest extends TestCase
{
    private function topBarSection(): array
    {
        $menus = $this->createStub(MenuRepository::class);
        $menus->method('findAll')->willReturn([]);
        $provider = new CoreSettingsProvider($menus, new IconRegistry());

        foreach ($provider->getSettingsSections() as $section) {
            if ('topbar' === $section->key) {
                return $section->definitions;
            }
        }

        self::fail('topbar settings section not found');
    }

    public function testTopBarHasEnabledAndText(): void
    {
        $keys = array_map(static fn ($d) => $d->key, $this->topBarSection());

        self::assertContains('top_bar_enabled', $keys);
        self::assertContains('top_bar_text', $keys);
    }

    public function testSocialFieldsGeneratedFromBrandKeys(): void
    {
        $defs = $this->topBarSection();
        $byKey = [];
        foreach ($defs as $d) {
            $byKey[$d->key] = $d;
        }

        // One social_{brand}_url per brand icon — single-sourced with the registry, not hardcoded.
        foreach ((new IconRegistry())->brandKeys() as $brand) {
            $key = 'social_'.$brand.'_url';
            self::assertArrayHasKey($key, $byKey, "missing $key");
            self::assertSame(SettingType::STRING, $byKey[$key]->type);
            self::assertSame('', $byKey[$key]->default); // optional, empty by default
        }
    }

    #[DataProvider('urlCases')]
    public function testIsSafeUrl(string $url, bool $expected): void
    {
        self::assertSame($expected, TopBarExtension::isSafeUrl($url));
    }

    public static function urlCases(): array
    {
        return [
            'empty' => ['', false],
            'whitespace' => ['   ', false],
            'https' => ['https://github.com/x', true],
            'http' => ['http://example.com', true],
            'relative path' => ['/kontakt', true],
            'javascript scheme blocked' => ['javascript:alert(1)', false],
            'data scheme blocked' => ['data:text/html,x', false],
            'bare word' => ['github.com', false],
        ];
    }
}
