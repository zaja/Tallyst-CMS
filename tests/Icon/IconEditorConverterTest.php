<?php

namespace App\Tests\Icon;

use App\Content\ContentRenderer;
use App\Content\ShortcodeRegistry;
use App\Icon\IconEditorConverter;
use App\Icon\IconRegistry;
use App\Icon\IconRenderer;
use App\Icon\IconShortcode;
use PHPUnit\Framework\TestCase;

/**
 * Locks the [icon] <-> editor-marker conversion and, crucially, proves it agrees with the REAL
 * front pipeline (ContentRenderer + IconShortcode) so the two [icon] parsers can't drift —
 * mirroring the [image] converter coupling test.
 */
class IconEditorConverterTest extends TestCase
{
    private function converter(): IconEditorConverter
    {
        return new IconEditorConverter();
    }

    public function testForwardBuildsMarker(): void
    {
        $html = $this->converter()->toEditorHtml('[icon name=github]');

        self::assertStringContainsString('<span', $html);
        self::assertStringContainsString('data-tallyst-icon', $html);
        self::assertStringContainsString('data-name="github"', $html);
        self::assertStringNotContainsString('<svg', $html); // marker only — the SVG is a NodeView concern
    }

    public function testBackConvertsMarkerToShortcode(): void
    {
        $stored = $this->converter()->toStored('<span data-tallyst-icon="" data-name="github"></span>');

        self::assertSame('[icon name=github]', $stored);
    }

    public function testRoundTripIsStable(): void
    {
        $converter = $this->converter();
        $stored = '[icon name=github]';

        self::assertSame($stored, $converter->toStored($converter->toEditorHtml($stored)));
    }

    public function testLabelRoundTrips(): void
    {
        $converter = $this->converter();
        $stored = $converter->toStored($converter->toEditorHtml('[icon name=github label="GitHub"]'));

        self::assertSame('[icon name=github label="GitHub"]', $stored);
    }

    public function testUnknownNameIsPreserved(): void
    {
        $converter = $this->converter();
        // The converter doesn't validate against the registry — an unknown name round-trips (the
        // front/NodeView degrade to empty). Keeps the boundary dumb + graceful.
        self::assertSame('[icon name=nepostoji]', $converter->toStored($converter->toEditorHtml('[icon name=nepostoji]')));
    }

    public function testDisjointLeavesOtherShortcodesAndImagesUntouched(): void
    {
        $converter = $this->converter();

        // Other shortcodes are not [icon] — left verbatim in toEditorHtml.
        self::assertSame('[image id=5] [form id=2]', $converter->toEditorHtml('[image id=5] [form id=2]'));
        // A marked <img> is not our icon span — left verbatim in toStored.
        $img = '<img data-tallyst-image data-id="5" src="/x.jpg">';
        self::assertSame($img, $converter->toStored($img));
    }

    public function testCouplingWithRealFrontPipeline(): void
    {
        // The stored shortcode the converter produces must render a real <svg> through the SAME
        // IconShortcode/IconRenderer the front uses — the two [icon] sides can't diverge.
        $converter = $this->converter();
        $stored = $converter->toStored('<span data-tallyst-icon data-name="github"></span>');

        $front = (new ContentRenderer(new ShortcodeRegistry([
            new IconShortcode(new IconRenderer(new IconRegistry())),
        ])))->render($stored);

        self::assertStringContainsString('<svg', $front);
        self::assertStringContainsString('class="tallyst-icon"', $front);
    }
}
