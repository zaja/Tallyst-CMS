<?php

namespace App\Controller\Admin;

use App\Entity\Setting;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// Raw key/value CRUD — unlinked from the menu (replaced by the typed SettingsController),
// but the routes still exist, so keep them admin-only.
#[IsGranted('ROLE_ADMIN')]
class SettingCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Setting::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Postavka')
            ->setEntityLabelInPlural('Postavke');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Ključ');
        yield TextareaField::new('value', 'Vrijednost');
    }
}
