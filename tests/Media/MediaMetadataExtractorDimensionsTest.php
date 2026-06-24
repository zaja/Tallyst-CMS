<?php

namespace App\Tests\Media;

use PHPUnit\Framework\TestCase;
use Tallyst\Media\Entity\Media;
use Tallyst\Media\Service\MediaMetadataExtractor;

/**
 * Pixel dimensions are captured from the file (single getimagesize) and are null-safe for
 * non-images / missing files — so the backfill + display never throw.
 */
class MediaMetadataExtractorDimensionsTest extends TestCase
{
    private MediaMetadataExtractor $extractor;
    private array $tempFiles = [];

    protected function setUp(): void
    {
        if (!\function_exists('imagecreatetruecolor') || !\function_exists('imagepng')) {
            self::markTestSkipped('GD extension not available.');
        }
        $this->extractor = new MediaMetadataExtractor();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            @unlink($f);
        }
    }

    public function testExtractsDimensionsFromImage(): void
    {
        $path = $this->makePng(20, 10);

        $meta = $this->extractor->extract($path, 'demo.png');

        self::assertSame(20, $meta['width']);
        self::assertSame(10, $meta['height']);
    }

    public function testApplyToMediaSetsDimensionsAndLabel(): void
    {
        $media = new Media();
        $this->extractor->applyToMedia($media, $this->makePng(800, 600), 'demo.png');

        self::assertSame(800, $media->getWidth());
        self::assertSame(600, $media->getHeight());
        self::assertSame('800×600 px', $media->getDimensionsLabel());
    }

    public function testNonImageYieldsNullDimensions(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'meta');
        $this->tempFiles[] = $path;
        file_put_contents($path, 'not an image');

        $meta = $this->extractor->extract($path, 'notes.txt');

        self::assertNull($meta['width']);
        self::assertNull($meta['height']);
    }

    public function testMissingFileYieldsNullDimensions(): void
    {
        $meta = $this->extractor->extract('/no/such/file.png', 'gone.png');

        self::assertNull($meta['width']);
        self::assertNull($meta['height']);
    }

    public function testDimensionsLabelNullWhenUnknown(): void
    {
        self::assertNull((new Media())->getDimensionsLabel());
    }

    private function makePng(int $w, int $h): string
    {
        $path = tempnam(sys_get_temp_dir(), 'img').'.png';
        $this->tempFiles[] = $path;
        $im = imagecreatetruecolor($w, $h);
        imagepng($im, $path);

        return $path;
    }
}
