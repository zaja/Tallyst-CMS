<?php

namespace App\Tests\Media;

use PHPUnit\Framework\TestCase;
use Tallyst\Media\Service\MediaImageHelper;
use Tallyst\Media\Service\ThumbnailCacheNaming;

/**
 * Locks the WebP cache-naming contract: the URL MediaImageHelper::url() serves and the cache
 * path ThumbnailWarmer stores at MUST be identical (a divergence 404s under nginx, the pre-warm
 * gotcha). Both route through ThumbnailCacheNaming — this test proves url() == the warmer's path
 * for every filter, that webp filters carry a .webp SUFFIX (appended, never replacing — so
 * jpg/png of the same name don't collide), and that favicon stays in its source format.
 */
class ThumbnailCacheNamingTest extends TestCase
{
    private const NAME = 'photo.jpg';

    /**
     * The single lock: for every filter, MediaImageHelper::url() equals
     * `/media/cache/<filter>/` + the exact cache path the warmer stores at. If url() ever stops
     * routing through ThumbnailCacheNaming, this breaks.
     */
    public function testUrlMatchesTheWarmedCachePathForEveryFilter(): void
    {
        $helper = new MediaImageHelper();

        foreach (['thumb', 'medium', 'hero', 'favicon'] as $filter) {
            $warmerPath = ThumbnailCacheNaming::cachePath(self::NAME, $filter); // what ThumbnailWarmer stores + isStored at
            $url = $helper->url(self::NAME, $filter);

            self::assertSame('/media/cache/'.$filter.'/'.$warmerPath, $url, "url() must equal the warmed cache path for filter '$filter'");
        }
    }

    public function testWebpFiltersAppendWebpSuffix(): void
    {
        foreach (ThumbnailCacheNaming::WEBP_FILTERS as $filter) {
            self::assertTrue(ThumbnailCacheNaming::isWebpFilter($filter), "$filter must be a webp filter");
            self::assertSame('media/uploads/photo.jpg.webp', ThumbnailCacheNaming::cachePath(self::NAME, $filter));
        }

        // Append, NEVER replace: a .png source keeps its name + .webp, so it can't collide with a .jpg of the same stem.
        self::assertSame('media/uploads/photo.png.webp', ThumbnailCacheNaming::cachePath('photo.png', 'medium'));
    }

    public function testFaviconStaysSourceFormat(): void
    {
        self::assertFalse(ThumbnailCacheNaming::isWebpFilter('favicon'));
        // No .webp suffix — favicon keeps the source name (and the source format on disk).
        self::assertSame('media/uploads/icon.png', ThumbnailCacheNaming::cachePath('icon.png', 'favicon'));
        self::assertSame('/media/cache/favicon/media/uploads/icon.png', (new MediaImageHelper())->url('icon.png', 'favicon'));
    }
}
