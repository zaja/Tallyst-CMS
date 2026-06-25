<?php

namespace Tallyst\FormBuilder;

use App\Module\AdminModuleInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use Tallyst\FormBuilder\Controller\Admin\OrderCrudController;

/**
 * Module metadata + admin presence. Auto-tagged app.module (via ModuleInterface)
 * so it shows up in ModuleRegistry and the admin "Moduli" section.
 */
class FormBuilderModule implements AdminModuleInterface
{
    public function getName(): string
    {
        return 'form_builder';
    }

    public function getLabel(): string
    {
        return 'Form Builder';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        // admin-domain key — the Moduli page renders module.description|trans (admin).
        return 'admin.module.desc.form_builder';
    }

    public function isCore(): bool
    {
        return true; // mandatory: orders/payments/webhooks + the [form] shortcode depend on it
    }

    public function getAdminMenuItems(): array
    {
        // Form management + orders are admin-only (the controllers carry #[IsGranted('ROLE_ADMIN')]);
        // setPermission here just hides the links from editors. Forme = a content tool (Sadržaj);
        // Narudžbe = sales data (Prodaja). The dashboard places each group into the matching section.
        return [
            // Labels are admin-domain keys — the dashboard's translation_domain ('admin') translates
            // every menu item (incl. these module-contributed ones) via MenuFactory.
            AdminModuleInterface::SECTION_CONTENT => [
                MenuItem::linkToRoute('admin.menu.forms', 'fa fa-wpforms', 'form_builder_admin_index')->setPermission('ROLE_ADMIN'),
            ],
            AdminModuleInterface::SECTION_SALES => [
                MenuItem::linkTo(OrderCrudController::class, 'admin.menu.orders', 'fa fa-receipt')->setPermission('ROLE_ADMIN'),
            ],
        ];
    }
}
