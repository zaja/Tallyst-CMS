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
        // admin-domain key — the Moduli page renders module.description|trans (admin).
        return 'admin.module.desc.media';
    }

    public function isCore(): bool
    {
        return true; // foundational: uploads, thumbnails, the Tiptap editor + featured images depend on it
    }

    public function getAdminMenuItems(): array
    {
        // "Mediji" (the media library) is content — visible to ROLE_EDITOR. Branding (logo +
        // favicon) moved into Postavke → Branding (admin-only), so no standalone item here.
        return [
            AdminModuleInterface::SECTION_CONTENT => [
                // Admin-domain key — translated by the dashboard's translation_domain via MenuFactory.
                MenuItem::linkTo(MediaCrudController::class, 'admin.menu.media', 'fa fa-images'),
            ],
        ];
    }
}
