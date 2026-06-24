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
        return 'Gradnja formi s uvjetnom logikom; [form id=N] pretvara sadržaj stranice u formu.';
    }

    public function isCore(): bool
    {
        return true; // mandatory: orders/payments/webhooks + the [form] shortcode depend on it
    }

    public function getAdminMenuItems(): iterable
    {
        // Form management + orders are admin-only (the controllers carry
        // #[IsGranted('ROLE_ADMIN')]); setPermission here just hides the links from editors.
        yield MenuItem::linkToRoute('Forme', 'fa fa-wpforms', 'form_builder_admin_index')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(OrderCrudController::class, 'Narudžbe', 'fa fa-receipt')->setPermission('ROLE_ADMIN');
    }
}
