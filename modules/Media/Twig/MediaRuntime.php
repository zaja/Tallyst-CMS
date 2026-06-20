<?php

namespace Tallyst\Media\Twig;

use App\Repository\SettingRepository;
use Tallyst\Media\Entity\Media;
use Tallyst\Media\Repository\MediaRepository;
use Twig\Environment;
use Twig\Extension\RuntimeExtensionInterface;

class MediaRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly SettingRepository $settings,
        private readonly MediaRepository $media,
        private readonly Environment $twig,
    ) {
    }

    public function siteName(): string
    {
        return $this->settings->get('site_name', 'Tallyst') ?: 'Tallyst';
    }

    /**
     * Deterministic RELATIVE URL of a media thumbnail's cached file. The thumbnail is
     * warmed on upload (see ThumbnailWarmer), so this points at a real static file
     * nginx serves directly. We build the path ourselves (Liip's web_path layout)
     * rather than the on-demand resolve URL — which nginx 404s (image extension, not a
     * real file) — and rather than Liip's absolute URL (scheme/mixed-content risk).
     */
    public function mediaThumbUrl(?string $imageName, string $filter = 'thumb'): ?string
    {
        if (null === $imageName || '' === $imageName) {
            return null;
        }

        return '/media/cache/'.$filter.'/media/uploads/'.$imageName;
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

        return null !== $logo ? $this->mediaThumbUrl($logo->getImageName(), $filter) : null;
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
            'logoUrl' => null !== $logo ? $this->mediaThumbUrl($logo->getImageName(), 'medium') : null,
            'logoAlt' => null !== $logo ? ($logo->getAlt() ?: $siteName) : $siteName,
        ]);
    }
}
