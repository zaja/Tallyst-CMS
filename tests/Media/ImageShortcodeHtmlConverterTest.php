<?php

namespace App\Tests\Media;

use App\Content\ContentRenderer;
use App\Content\ShortcodeRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tallyst\Media\Entity\Media;
use Tallyst\Media\Repository\MediaRepository;
use Tallyst\Media\Service\ImageShortcodeHtmlConverter;
use Tallyst\Media\Service\MediaImageHelper;
use Tallyst\Media\Shortcode\ImageShortcode;

/**
 * Locks the [image …] shortcode <-> editor <img> conversion and, crucially, proves it
 * agrees with the REAL front pipeline (ContentRenderer + ImageShortcode) so the two
 * [image] parsers can't drift.
 */
class ImageShortcodeHtmlConverterTest extends TestCase
{
    private function media(string $imageName = 'photo.jpg', ?string $alt = null): Media
    {
        $m = new Media();
        $m->setImageName($imageName);
        $m->setAlt($alt);

        return $m;
    }

    private function converter(?Media $found): ImageShortcodeHtmlConverter
    {
        $repo = $this->createStub(MediaRepository::class);
        $repo->method('find')->willReturn($found);

        return new ImageShortcodeHtmlConverter($repo, new MediaImageHelper());
    }

    public function testForwardBuildsMarkedImgWithLiipUrl(): void
    {
        $html = $this->converter($this->media())->toEditorHtml('[image id=5]');

        self::assertStringContainsString('data-tallyst-image', $html);
        self::assertStringContainsString('data-id="5"', $html);
        // WebP serving: medium is a webp-format filter → the cache file carries a .webp suffix.
        self::assertStringContainsString('src="/media/cache/medium/media/uploads/photo.jpg.webp"', $html);
    }

    public function testForwardPreservesSizeAlignAlt(): void
    {
        $html = $this->converter($this->media())->toEditorHtml('[image id=5 size=thumb align=left alt="Pozdrav"]');

        self::assertStringContainsString('data-size="thumb"', $html);
        self::assertStringContainsString('data-align="left"', $html);
        self::assertStringContainsString('alt="Pozdrav"', $html);
        self::assertStringContainsString('/media/cache/thumb/media/uploads/photo.jpg.webp', $html);
    }

    public function testForwardPreservesFullWidth(): void
    {
        $html = $this->converter($this->media())->toEditorHtml('[image id=5 width=full]');

        self::assertStringContainsString('data-width="full"', $html);
    }

    public function testForwardLeavesOtherContentAndFormShortcodeUntouched(): void
    {
        $in = '<p>Tekst</p>[form id=2]<h2>Naslov</h2>';
        self::assertSame($in, $this->converter($this->media())->toEditorHtml($in));
    }

    public function testForwardNullSafeForDeletedMedia(): void
    {
        // Media not found -> empty src but id preserved, so the shortcode survives a save.
        $html = $this->converter(null)->toEditorHtml('[image id=9]');
        self::assertStringContainsString('data-id="9"', $html);
        self::assertStringContainsString('src=""', $html);
    }

    public function testReverseRebuildsShortcode(): void
    {
        $img = '<img data-tallyst-image="" data-id="5" data-size="thumb" data-align="left" src="/x.jpg" alt="Pozdrav">';
        self::assertSame('[image id=5 size=thumb align=left alt="Pozdrav"]', $this->converter(null)->toStored($img));
    }

    public function testReverseOmitsMediumDefaultAndEmptyAttrs(): void
    {
        $img = '<img src="/x.jpg" data-id="5" data-size="medium" data-align="" alt="" data-tallyst-image>';
        self::assertSame('[image id=5]', $this->converter(null)->toStored($img));
    }

    public function testReverseEmitsFullWidthAndOmitsNormal(): void
    {
        $full = '<img data-tallyst-image data-id="5" data-width="full" src="/x.jpg" alt="">';
        self::assertSame('[image id=5 width=full]', $this->converter(null)->toStored($full));

        // Empty/normal width is the default — never written.
        $normal = '<img data-tallyst-image data-id="5" data-width="" src="/x.jpg" alt="">';
        self::assertSame('[image id=5]', $this->converter(null)->toStored($normal));
    }

    public function testReverseLeavesPlainImgAndTextUntouched(): void
    {
        $in = '<p>Tekst</p><img src="/other.jpg">[form id=2]';
        self::assertSame($in, $this->converter(null)->toStored($in));
    }

    /** @return iterable<string, array{0: string}> */
    public static function roundTripCases(): iterable
    {
        yield 'id only' => ['[image id=5]'];
        yield 'with size+align+alt' => ['[image id=5 size=thumb align=left alt="Pozdrav"]'];
        yield 'full width' => ['[image id=5 width=full]'];
        yield 'inline among text' => ['<p>Prije</p>[image id=5]<p>[form id=2] poslije</p>'];
    }

    #[DataProvider('roundTripCases')]
    public function testStoredEditorStoredRoundTrip(string $stored): void
    {
        $c = $this->converter($this->media());
        self::assertSame($stored, $c->toStored($c->toEditorHtml($stored)));
    }

    /**
     * COUPLING: a shortcode produced by the converter (reverse) must render through the
     * real ContentRenderer + ImageShortcode IDENTICALLY to a direct MediaImageHelper::img
     * call — so the editor's [image] grammar and the front pipeline can't diverge.
     */
    public function testConverterOutputRendersIdenticallyOnFront(): void
    {
        $media = $this->media('photo.jpg', 'Alt');
        $repo = $this->createStub(MediaRepository::class);
        $repo->method('find')->willReturn($media);
        $images = new MediaImageHelper();

        $img = '<img data-tallyst-image data-id="5" data-size="thumb" data-align="left" alt="Pozdrav" src="/x.jpg">';
        $shortcode = (new ImageShortcodeHtmlConverter($repo, $images))->toStored($img);

        $renderer = new ContentRenderer(new ShortcodeRegistry([new ImageShortcode($repo, $images)]));
        $front = $renderer->render($shortcode);

        self::assertSame($images->img($media, 'thumb', 'Pozdrav', 'left'), $front);
    }

    /** Full width on the front: rendered from the larger 'hero' source with the .media-img-full class. */
    public function testFullWidthRendersAtHeroWithFullClass(): void
    {
        $media = $this->media('photo.jpg', 'Alt');
        $repo = $this->createStub(MediaRepository::class);
        $repo->method('find')->willReturn($media);
        $images = new MediaImageHelper();

        $img = '<img data-tallyst-image data-id="5" data-width="full" alt="Pozdrav" src="/x.jpg">';
        $shortcode = (new ImageShortcodeHtmlConverter($repo, $images))->toStored($img);
        self::assertSame('[image id=5 width=full alt="Pozdrav"]', $shortcode);

        $front = (new ContentRenderer(new ShortcodeRegistry([new ImageShortcode($repo, $images)])))->render($shortcode);

        self::assertStringContainsString('media-img-full', $front);
        self::assertStringContainsString('/media/cache/hero/', $front, 'full width renders from the hero source (not blurry medium)');
        self::assertSame($images->img($media, 'hero', 'Pozdrav', null, 'full'), $front);
    }
}
