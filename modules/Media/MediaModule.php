<?php

namespace Tallyst\Media;

use App\Module\AdminModuleInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use Tallyst\Media\Controller\Admin\MediaCrudController;

/**
 * Media module metadata + admin presence. Default-enabled (foundational); the
 * ModuleStateManager already treats modules as enabled unless a Setting disables them.
 */
class MediaModule implements AdminModuleInterface
{
    public function getName(): string
    {
        return 'media';
    }

    public function getLabel(): string
    {
        return 'Media';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Upload i upravljanje slikama (thumbnaili), logo/branding.';
    }

    public function getAdminMenuItems(): iterable
    {
        // "Mediji" (the media library) is content — visible to ROLE_EDITOR. Branding is
        // visual identity (admin-only); its controller carries #[IsGranted('ROLE_ADMIN')].
        yield MenuItem::linkTo(MediaCrudController::class, 'Mediji', 'fa fa-images');
        yield MenuItem::linkToRoute('Branding', 'fa fa-palette', 'media_branding')->setPermission('ROLE_ADMIN');
    }
}
