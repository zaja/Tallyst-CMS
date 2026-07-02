<?php

namespace App\Tests\Icon;

use App\Content\ContentRenderer;
use App\Content\ShortcodeRegistry;
use App\Icon\IconRegistry;
use App\Icon\IconRenderer;
use App\Icon\IconShortcode;
use PHPUnit\Framework\TestCase;

/**
 * Locks the [icon name=X] shortcode through the REAL front pipeline (ContentRenderer +
 * ShortcodeRegistry): a known name renders an inline SVG, an unknown name degrades to nothing
 * (like a missing [image] id), and surrounding content is preserved.
 */
class IconShortcodeTest extends TestCase
{
    private function renderer(): ContentRenderer
    {
        $shortcode = new IconShortcode(new IconRenderer(new IconRegistry()));

        return new ContentRenderer(new ShortcodeRegistry([$shortcode]));
    }

    public function testKnownNameRendersSvgInContent(): void
    {
        $out = $this->renderer()->render('<p>Pratite nas [icon name=github] ovdje</p>');

        self::assertStringContainsString('<svg', $out);
        self::assertStringContainsString('class="tallyst-icon"', $out);
        self::assertStringContainsString('Pratite nas', $out);
        self::assertStringContainsString('ovdje', $out);
        self::assertStringNotContainsString('[icon', $out);
    }

    public function testLabelAttributePassesThrough(): void
    {
        $out = $this->renderer()->render('[icon name=github label="GitHub"]');

        self::assertStringContainsString('role="img"', $out);
        self::assertStringContainsString('aria-label="GitHub"', $out);
    }

    public function testUnknownNameRendersNothing(): void
    {
        $out = $this->renderer()->render('<p>prije [icon name=nepostoji] poslije</p>');

        self::assertSame('<p>prije  poslije</p>', $out);
        self::assertStringNotContainsString('<svg', $out);
    }

    public function testMissingNameRendersNothing(): void
    {
        self::assertSame('', $this->renderer()->render('[icon]'));
    }

    public function testShortcodeName(): void
    {
        self::assertSame('icon', (new IconShortcode(new IconRenderer(new IconRegistry())))->getName());
    }
}
