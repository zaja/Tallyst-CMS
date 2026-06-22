<?php

namespace Tallyst\Media\Service;

use Tallyst\Media\Entity\Media;

/**
 * Single source of truth for turning a Media into an <img>. Used by branding,
 * featured images and the [image id=N] shortcode — so the markup, URL shape and
 * escaping live in ONE place.
 *
 * Security: the URL is generated internally; the only caller-supplied bits are the
 * filter (whitelisted to defined Liip filters), the align (whitelisted to fixed CSS
 * classes) and the alt (HTML-escaped). There is NO arbitrary attribute passthrough,
 * so authored content like [image align="x" onerror=...] cannot inject markup.
 */
class MediaImageHelper
{
    /** Defined + warmed Liip filters (see liip_imagine.yaml + ThumbnailWarmer). */
    private const FILTERS = ['thumb', 'medium', 'hero', 'favicon'];
    private const DEFAULT_FILTER = 'medium';

    /** Whitelisted alignment → CSS class. */
    private const ALIGN_CLASSES = [
        'left' => 'media-align-left',
        'right' => 'media-align-right',
        'center' => 'media-align-center',
    ];

    /** Deterministic RELATIVE URL of the warmed cached file (nginx-served). */
    public function url(?string $imageName, string $filter = self::DEFAULT_FILTER): ?string
    {
        if (null === $imageName || '' === $imageName) {
            return null;
        }

        return '/media/cache/'.$this->filter($filter).'/media/uploads/'.$imageName;
    }

    /**
     * Safe <img> for a media, or '' when there's nothing to render.
     */
    public function img(?Media $media, string $filter = self::DEFAULT_FILTER, ?string $altOverride = null, ?string $align = null): string
    {
        if (null === $media) {
            return '';
        }

        $url = $this->url($media->getImageName(), $filter);
        if (null === $url) {
            return '';
        }

        $alt = $altOverride ?? $media->getAlt() ?? $media->getTitle() ?? '';

        $classes = ['media-img'];
        if (null !== $align && isset(self::ALIGN_CLASSES[$align])) {
            $classes[] = self::ALIGN_CLASSES[$align];
        }

        return \sprintf(
            '<img src="%s" alt="%s" class="%s" loading="lazy">',
            htmlspecialchars($url, \ENT_QUOTES),
            htmlspecialchars($alt, \ENT_QUOTES),
            implode(' ', $classes),
        );
    }

    private function filter(string $filter): string
    {
        return \in_array($filter, self::FILTERS, true) ? $filter : self::DEFAULT_FILTER;
    }
}
