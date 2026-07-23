<?php

namespace Tallyst\Media\Service;

use Liip\ImagineBundle\Imagine\Cache\CacheManager;

/**
 * Removes the Liip cache files for one uploaded image, across every filter — the cleanup
 * counterpart to ThumbnailWarmer::warm(). SINGLE place that knows how to actually delete
 * cached thumbnails, shared by MediaCacheCleanupListener (prevention, on replace/delete)
 * and the one-off orphan-sweep command — so the .webp-suffix logic below is never
 * duplicated.
 *
 * ⚠ The active resolver (Liip's default `web_path`) does a PLAIN concatenation of the
 * given path + filter — it has NO knowledge of our .webp-suffix convention. Calling
 * remove() with a bare imageName would silently miss thumb/medium/hero (Symfony's
 * Filesystem::remove() on a non-existent path is a safe no-op, not an error — so nothing
 * would appear broken, it just wouldn't clean up). Each filter therefore gets its OWN
 * remove() call with ThumbnailCacheNaming::cachePath() as the path — mirrors
 * ThumbnailWarmer::warm()'s per-filter loop exactly, store() swapped for remove().
 */
class ThumbnailCleaner
{
    /** Keep in sync with ThumbnailWarmer::FILTERS (and the other two allowlists it notes). */
    private const FILTERS = ['thumb', 'medium', 'hero', 'favicon'];

    public function __construct(
        private readonly CacheManager $cacheManager,
    ) {
    }

    /**
     * Removes every filter's cached file for $imageName. Safe to call for a name that was
     * never warmed, or whose files are already gone — CacheManager::remove() no-ops on a
     * missing file rather than throwing (confirmed from Symfony\Component\Filesystem\
     * Filesystem::remove()'s source: unlink() failing on a non-existent path never raises).
     */
    public function remove(string $imageName): void
    {
        if ('' === $imageName) {
            return;
        }

        foreach (self::FILTERS as $filter) {
            $this->cacheManager->remove(ThumbnailCacheNaming::cachePath($imageName, $filter), $filter);
        }
    }
}
