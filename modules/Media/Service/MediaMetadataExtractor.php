<?php

namespace Tallyst\Media\Service;

use Tallyst\Media\Entity\Media;

/**
 * Derives a sensible title + alt for an image (WordPress-style), applied only to EMPTY
 * fields so it never overwrites the admin's input. Priority:
 *   (a) IPTC ObjectName (2#005) -> title, Caption/Abstract (2#120) -> alt
 *   (b) EXIF ImageDescription -> fallback for both (only the description tag; never GPS
 *       or other EXIF)
 *   (c) the original filename without extension, separators (_ - .) -> spaces
 *
 * Robust by design: if the file is missing/unreadable or a reader fails, it degrades to
 * the filename. Since originalName is always present, every media still gets a title/alt
 * — so the backfill is safe even when a file is gone.
 */
class MediaMetadataExtractor
{
    /** Fill Media's empty title/alt from the file at $path (+ its original filename). */
    public function applyToMedia(Media $media, string $path, ?string $originalName): void
    {
        $meta = $this->extract($path, $originalName);

        if ($this->isEmpty($media->getTitle()) && null !== $meta['title']) {
            $media->setTitle($meta['title']);
        }
        if ($this->isEmpty($media->getAlt()) && null !== $meta['alt']) {
            $media->setAlt($meta['alt']);
        }
    }

    /**
     * @return array{title: ?string, alt: ?string}
     */
    public function extract(string $path, ?string $originalName): array
    {
        $iptcTitle = $iptcCaption = $exifDescription = null;

        if ('' !== $path && is_file($path) && is_readable($path)) {
            [$iptcTitle, $iptcCaption] = $this->iptc($path);
            $exifDescription = $this->exifDescription($path);
        }

        $fromName = $this->fromFilename($originalName);

        return [
            'title' => $this->clean($iptcTitle) ?? $this->clean($exifDescription) ?? $fromName,
            'alt' => $this->clean($iptcCaption) ?? $this->clean($exifDescription) ?? $fromName,
        ];
    }

    /** @return array{0: ?string, 1: ?string} [ObjectName, Caption] */
    private function iptc(string $path): array
    {
        try {
            $info = [];
            $size = @getimagesize($path, $info);
            if (false === $size || !isset($info['APP13'])) {
                return [null, null];
            }
            $iptc = @iptcparse($info['APP13']);
            if (!\is_array($iptc)) {
                return [null, null];
            }

            return [$iptc['2#005'][0] ?? null, $iptc['2#120'][0] ?? null];
        } catch (\Throwable) {
            return [null, null];
        }
    }

    private function exifDescription(string $path): ?string
    {
        // Graceful degradation: no exif extension -> skip (filename fallback still applies).
        if (!\function_exists('exif_read_data')) {
            return null;
        }

        try {
            $exif = @exif_read_data($path);

            return \is_array($exif) ? ($exif['ImageDescription'] ?? null) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Original filename -> human title: drop extension, separators -> spaces, collapse. */
    private function fromFilename(?string $name): ?string
    {
        if (null === $name || '' === $name) {
            return null;
        }

        $base = pathinfo($name, \PATHINFO_FILENAME);
        $base = preg_replace('/[_\-.]+/', ' ', $base) ?? $base;
        $base = preg_replace('/\s+/', ' ', $base) ?? $base;

        return $this->clean(trim($base));
    }

    /** Trim, ensure UTF-8, cap to the column length; null when empty. */
    private function clean(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $value = trim($value);
        if ('' === $value) {
            return null;
        }
        if (!mb_check_encoding($value, 'UTF-8')) {
            // IPTC/EXIF text is often Latin-1.
            $value = mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
        }

        return mb_substr($value, 0, 255);
    }

    private function isEmpty(?string $value): bool
    {
        return null === $value || '' === trim($value);
    }
}
