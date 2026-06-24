<?php

namespace Tallyst\Media\Shortcode;

use App\Content\ShortcodeInterface;
use Tallyst\Media\Repository\MediaRepository;
use Tallyst\Media\Service\MediaImageHelper;

/**
 * [image id=N size=medium align=left alt="..."] — embeds a Media image in content,
 * mirroring [form id=N]. Auto-registered via the app.shortcode tag.
 *
 * Hardening: `size` is whitelisted by MediaImageHelper (defined Liip filters,
 * medium default); `align` maps to a fixed CSS class; `alt` is escaped; no arbitrary
 * attributes reach the <img>. Missing/deleted id renders nothing (a comment) — never
 * an error.
 */
class ImageShortcode implements ShortcodeInterface
{
    public function __construct(
        private readonly MediaRepository $media,
        private readonly MediaImageHelper $images,
    ) {
    }

    public function getName(): string
    {
        return 'image';
    }

    public function render(array $attributes, ?string $content = null): string
    {
        $id = (int) ($attributes['id'] ?? 0);
        if ($id <= 0) {
            return '';
        }

        $media = $this->media->find($id);
        if (null === $media) {
            return \sprintf('<!-- Tallyst: image #%d not found -->', $id);
        }

        $size = isset($attributes['size']) ? (string) $attributes['size'] : 'medium';
        $align = isset($attributes['align']) ? (string) $attributes['align'] : null;
        $alt = isset($attributes['alt']) ? (string) $attributes['alt'] : null;
        $width = isset($attributes['width']) ? (string) $attributes['width'] : null;

        // Full-width renders from the larger 'hero' source so a 100%-wide image isn't an
        // upscaled-blurry medium (600px). Anything but 'full' is normal width.
        $filter = 'full' === $width ? 'hero' : $size;

        return $this->images->img($media, $filter, $alt, $align, $width);
    }
}
