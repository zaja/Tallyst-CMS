<?php

namespace Tallyst\Media\Twig;

use App\Repository\SettingRepository;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Tallyst\Media\Entity\Media;
use Tallyst\Media\Repository\MediaRepository;
use Twig\Environment;
use Twig\Extension\RuntimeExtensionInterface;

class MediaRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly SettingRepository $settings,
        private readonly MediaRepository $media,
        private readonly CacheManager $imagineCache,
        private readonly Environment $twig,
    ) {
    }

    public function siteName(): string
    {
        return $this->settings->get('site_name', 'Tallyst') ?: 'Tallyst';
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
        if (null === $logo || null === $logo->getImageName()) {
            return null;
        }

        return $this->imagineCache->getBrowserPath('media/uploads/'.$logo->getImageName(), $filter);
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

        $logoUrl = null !== $logo && null !== $logo->getImageName()
            ? $this->imagineCache->getBrowserPath('media/uploads/'.$logo->getImageName(), 'medium')
            : null;

        return $this->twig->render('branding.html.twig', [
            'siteName' => $siteName,
            'logoUrl' => $logoUrl,
            'logoAlt' => null !== $logo ? ($logo->getAlt() ?: $siteName) : $siteName,
        ]);
    }
}
