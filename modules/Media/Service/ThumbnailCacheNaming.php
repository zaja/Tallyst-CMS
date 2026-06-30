<?php

namespace Tallyst\Media\Service;

/**
 * SINGLE source of truth for the cache-relative path of a warmed Liip thumbnail. Used by BOTH
 * MediaImageHelper::url() (the deterministic, nginx-served URL) AND ThumbnailWarmer (where it
 * stores + checks isStored), so the served URL and the file on disk can NEVER diverge — a
 * mismatch would 404 under nginx (the pre-warm/static-serving gotcha).
 *
 * WebP serving: the filters that carry `format: webp` in liip_imagine.yaml (thumb/medium/hero)
 * output WebP bytes, but Liip keeps the SOURCE filename in the manual-warm path (proven: it does
 * NOT append .webp itself). So we APPEND a .webp suffix to the cache path — `photo.jpg.webp` —
 * so nginx serves the real extension as `image/webp` (no MIME mismatch). APPEND (not replace) so
 * `photo.jpg` and `photo.png` don't collide into one `photo.webp`. favicon is NOT a webp filter
 * (uneven WebP-favicon support) → it keeps the source format + name, no suffix.
 */
final class ThumbnailCacheNaming
{
    /**
     * Filters whose output is WebP (must match `format: webp` in liip_imagine.yaml) → their
     * cache file gets a .webp suffix. favicon is intentionally excluded (source format).
     */
    public const WEBP_FILTERS = ['thumb', 'medium', 'hero'];

    /**
     * Cache-relative path of the warmed file for an uploaded image under a filter, e.g.
     *   media/uploads/photo.jpg.webp   (webp filters — source name + .webp suffix)
     *   media/uploads/icon.png         (favicon — source format, no suffix)
     * The SOURCE path the warmer loads from is always `media/uploads/<imageName>` (unsuffixed) —
     * only this cache path carries the suffix.
     */
    public static function cachePath(string $imageName, string $filter): string
    {
        return 'media/uploads/'.(self::isWebpFilter($filter) ? $imageName.'.webp' : $imageName);
    }

    public static function isWebpFilter(string $filter): bool
    {
        return \in_array($filter, self::WEBP_FILTERS, true);
    }
}
