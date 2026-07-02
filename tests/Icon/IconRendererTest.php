<?php

namespace App\Tests\Icon;

use App\Icon\IconRegistry;
use App\Icon\IconRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Locks the icon renderer's contract: it emits ONLY allowlisted registry SVG (injection-safe),
 * an unknown key renders nothing, and the accessibility mode flips on the optional label.
 */
class IconRendererTest extends TestCase
{
    private function renderer(): IconRenderer
    {
        return new IconRenderer(new IconRegistry());
    }

    public function testKnownKeyRendersInlineSvg(): void
    {
        $out = $this->renderer()->render('search');

        self::assertStringContainsString('<svg', $out);
        self::assertStringContainsString('class="tallyst-icon"', $out);
        // currentColor + 1em so it inherits text colour/size without theme CSS.
        self::assertStringContainsString('fill="currentColor"', $out);
        self::assertStringContainsString('width="1em"', $out);
        self::assertStringContainsString('viewBox="0 0 512 512"', $out);
    }

    public function testUnknownKeyRendersEmpty(): void
    {
        self::assertSame('', $this->renderer()->render('does-not-exist'));
        self::assertSame('', $this->renderer()->render(''));
    }

    public function testDecorativeByDefault(): void
    {
        $out = $this->renderer()->render('github');

        self::assertStringContainsString('aria-hidden="true"', $out);
        self::assertStringNotContainsString('role="img"', $out);
    }

    public function testLabelMakesItMeaningfulAndIsEscaped(): void
    {
        $out = $this->renderer()->render('github', 'GitHub');

        self::assertStringContainsString('role="img"', $out);
        self::assertStringContainsString('aria-label="GitHub"', $out);
        self::assertStringNotContainsString('aria-hidden', $out);

        // The label is the only caller-supplied string → must be attribute-escaped.
        $escaped = $this->renderer()->render('github', 'a" onload="x');
        self::assertStringNotContainsString('onload="x', $escaped);
        self::assertStringContainsString('&quot;', $escaped);
    }

    public function testRegistryGroupsAndAllowlist(): void
    {
        $registry = new IconRegistry();

        self::assertContains('search', $registry->uiKeys());
        self::assertContains('github', $registry->brandKeys());
        self::assertTrue($registry->has('linkedin'));
        self::assertFalse($registry->has('nope'));
        self::assertNull($registry->get('nope'));
    }
}
