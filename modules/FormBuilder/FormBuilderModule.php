<?php

namespace Tallyst\FormBuilder;

use App\Module\AdminModuleInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;

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

    public function getAdminMenuItems(): iterable
    {
        yield MenuItem::linkToRoute('Forme', 'fa fa-wpforms', 'form_builder_admin_index');
    }
}
