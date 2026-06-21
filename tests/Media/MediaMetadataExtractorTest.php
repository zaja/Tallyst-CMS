<?php

namespace App\Tests\Media;

use PHPUnit\Framework\TestCase;
use Tallyst\Media\Entity\Media;
use Tallyst\Media\Service\MediaMetadataExtractor;

/**
 * Covers the filename branch + the "only empty" guard + robustness when the file is
 * missing (degrades to filename). IPTC/EXIF branches need real binary fixtures; the
 * filename path is the one every media falls back to and the one the backfill relies on.
 */
class MediaMetadataExtractorTest extends TestCase
{
    private MediaMetadataExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new MediaMetadataExtractor();
    }

    public function testFilenameFallbackWhenFileMissing(): void
    {
        $meta = $this->extractor->extract('/no/such/file.jpg', 'my_photo-01.jpg');

        self::assertSame('my photo 01', $meta['title']);
        self::assertSame('my photo 01', $meta['alt']);
    }

    public function testSeparatorsCollapseAndExtensionDropped(): void
    {
        $meta = $this->extractor->extract('', 'foo__bar--baz..qux.PNG');
        self::assertSame('foo bar baz qux', $meta['title']);
    }

    public function testNullOriginalNameYieldsNull(): void
    {
        $meta = $this->extractor->extract('', null);
        self::assertNull($meta['title']);
        self::assertNull($meta['alt']);
    }

    public function testTruncatesToColumnLength(): void
    {
        $long = str_repeat('a', 400).'.jpg';
        $meta = $this->extractor->extract('', $long);
        self::assertSame(255, mb_strlen((string) $meta['title']));
    }

    public function testApplyToMediaFillsEmptyOnly(): void
    {
        $media = new Media();
        $media->setTitle('Ručni naslov'); // must be preserved
        // alt left null -> should be filled

        $this->extractor->applyToMedia($media, '/no/such/file.jpg', 'a_b.jpg');

        self::assertSame('Ručni naslov', $media->getTitle());
        self::assertSame('a b', $media->getAlt());
    }

    public function testApplyToMediaNoOpWhenNothingToDerive(): void
    {
        $media = new Media();
        $this->extractor->applyToMedia($media, '', null);

        self::assertNull($media->getTitle());
        self::assertNull($media->getAlt());
    }
}
