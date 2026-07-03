<?php

namespace App\Tests\Font;

use App\Font\FontRegistry;
use App\Settings\SettingsManager;
use App\Twig\FontExtension;
use PHPUnit\Framework\TestCase;

/**
 * Locks the font mechanism: the curated registry choices, the 'system' default emitting nothing
 * (additive), and font_styles() producing @font-face (absolute /fonts/ URL) + the right :root
 * override per choice, deduping when display == body.
 */
class FontExtensionTest extends TestCase
{
    private function styles(string $display, string $body): string
    {
        $settings = $this->createStub(SettingsManager::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $k) => ['display_font' => $display, 'body_font' => $body][$k] ?? ''
        );

        return (new FontExtension($settings, new FontRegistry()))->fontStyles();
    }

    public function testRegistryChoicesAndKeys(): void
    {
        $registry = new FontRegistry();
        $choices = $registry->choices();

        // system + 7 curated demo fonts.
        self::assertCount(8, $choices);
        self::assertSame('system', $choices['System']);
        foreach (['Inter', 'Roboto', 'Manrope', 'Space Grotesk', 'IBM Plex Sans', 'Lora', 'Libre Baskerville'] as $label) {
            self::assertArrayHasKey($label, $choices, "missing font: $label");
        }
        self::assertFalse($registry->isWebFont('system'));
        self::assertTrue($registry->isWebFont('inter'));
        self::assertTrue($registry->isWebFont('libre-baskerville'));
        self::assertFalse($registry->isWebFont('nope'));
    }

    public function testSystemDefaultEmitsNothing(): void
    {
        // Both system (the default) → no @font-face, no override → the site stays on the OS stack.
        self::assertSame('', $this->styles('system', 'system'));
    }

    public function testBodyFontOverridesFontOnly(): void
    {
        $out = $this->styles('system', 'inter');

        self::assertStringContainsString("@font-face", $out);
        self::assertStringContainsString("url('/fonts/inter-400.woff2')", $out); // absolute, theme-independent
        self::assertStringContainsString('font-display:swap', $out);
        self::assertStringContainsString('--font:', $out);
        self::assertStringNotContainsString('--font-display:', $out);
    }

    public function testDisplayFontOverridesDisplayOnly(): void
    {
        $out = $this->styles('inter', 'system');

        self::assertStringContainsString("url('/fonts/inter-700.woff2')", $out);
        self::assertStringContainsString('--font-display:', $out);
        self::assertStringNotContainsString('--font:', $out);
    }

    public function testSameFontDedupesFacesAndSetsBothVars(): void
    {
        $out = $this->styles('inter', 'inter');

        // The 400 face is emitted ONCE even though both display + body chose Inter.
        self::assertSame(1, substr_count($out, "url('/fonts/inter-400.woff2')"));
        self::assertStringContainsString('--font:', $out);
        self::assertStringContainsString('--font-display:', $out);
    }
}
