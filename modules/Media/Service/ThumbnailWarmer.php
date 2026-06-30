<?php

namespace Tallyst\Media\Service;

use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Liip\ImagineBundle\Imagine\Data\DataManager;
use Liip\ImagineBundle\Imagine\Filter\FilterManager;
use Tallyst\Media\Entity\Media;

/**
 * Eagerly generates the Liip thumbnails for an uploaded image so the cached files
 * exist on disk (public/media/cache/<filter>/...). This is what makes thumbnails work
 * behind nginx: the static-asset location returns 404 for the on-demand "resolve" URL
 * (it ends in an image extension but isn't a real file), so we must reference the
 * REAL cached file — which only exists once warmed.
 */
class ThumbnailWarmer
{
    /** Keep in sync with config/packages/liip_imagine.yaml filter_sets. */
    private const FILTERS = ['thumb', 'medium', 'hero', 'favicon'];

    public function __construct(
        private readonly DataManager $dataManager,
        private readonly FilterManager $filterManager,
        private readonly CacheManager $cacheManager,
    ) {
    }

    public function warmMedia(Media $media): void
    {
        $name = $media->getImageName();
        if (null !== $name && '' !== $name) {
            $this->warm($name);
        }
    }

    public function warm(string $imageName): void
    {
        // The SOURCE is always loaded from the unsuffixed upload path; the CACHE path may carry a
        // .webp suffix (webp filters) — driven by ThumbnailCacheNaming, the SAME helper
        // MediaImageHelper::url() uses, so the warmed file matches the served URL exactly.
        $source = 'media/uploads/'.$imageName;

        foreach (self::FILTERS as $filter) {
            $cachePath = ThumbnailCacheNaming::cachePath($imageName, $filter);
            if ($this->cacheManager->isStored($cachePath, $filter)) {
                continue;
            }

            $binary = $this->filterManager->applyFilter($this->dataManager->find($filter, $source), $filter);
            $this->cacheManager->store($binary, $cachePath, $filter);
        }
    }
}
