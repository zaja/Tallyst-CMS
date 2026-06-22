<?php

namespace Tallyst\Media\Twig;

use App\Repository\SettingRepository;
use Tallyst\Media\Entity\Media;
use Tallyst\Media\Repository\MediaRepository;
use Tallyst\Media\Service\MediaImageHelper;
use Twig\Environment;
use Twig\Extension\RuntimeExtensionInterface;

class MediaRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly SettingRepository $settings,
        private readonly MediaRepository $media,
        private readonly MediaImageHelper $images,
        private readonly Environment $twig,
    ) {
    }

    public function siteName(): string
    {
        return $this->settings->get('site_name', 'Tallyst') ?: 'Tallyst';
    }

    /** Thumbnail URL of a media by its imageName (see MediaImageHelper). */
    public function mediaThumbUrl(?string $imageName, string $filter = 'thumb'): ?string
    {
        return $this->images->url($imageName, $filter);
    }

    /** Safe <img> for a media — used by featured images, etc. (shared markup). */
    public function mediaImg(?Media $media, string $filter = 'medium', ?string $align = null, ?string $alt = null): string
    {
        return $this->images->img($media, $filter, $alt, $align);
    }

    /**
     * The configured logo Media, or null. NULL-SAFE: logo_media_id is a loose Setting
     * reference, so a deleted Media simply resolves to null (no error).
     */
    public function brandingLogo(): ?Media
    {
        $id = $this->settings->get('logo_media_id');
        if (null === $id || '' === $id) {
            return null;
        }

        return $this->media->find((int) $id);
    }

    public function brandingLogoUrl(string $filter = 'medium'): ?string
    {
        $logo = $this->brandingLogo();

        return null !== $logo ? $this->images->url($logo->getImageName(), $filter) : null;
    }

    /**
     * Deterministic cached URL of the configured favicon (the pre-warmed `favicon` Liip
     * filter, NOT an on-demand resolve — see the nginx pre-warm gotcha), or null.
     * NULL-SAFE: favicon_media_id is a loose Setting reference (deleted Media → null → no tag).
     */
    public function faviconUrl(): ?string
    {
        $id = $this->settings->get('favicon_media_id');
        if (null === $id || '' === $id) {
            return null;
        }

        $favicon = $this->media->find((int) $id);

        return null !== $favicon ? $this->images->url($favicon->getImageName(), 'favicon') : null;
    }

    /**
     * Render the theme-overridable brand for the header: the logo (Liip-sized) when set
     * and still present, otherwise the site name as text. alt = the media's alt or the
     * site name (a11y).
     */
    public function renderBranding(): string
    {
        $logo = $this->brandingLogo();
        $siteName = $this->siteName();

        return $this->twig->render('branding.html.twig', [
            'siteName' => $siteName,
            'logoUrl' => null !== $logo ? $this->images->url($logo->getImageName(), 'medium') : null,
            'logoAlt' => null !== $logo ? ($logo->getAlt() ?: $siteName) : $siteName,
        ]);
    }
}
