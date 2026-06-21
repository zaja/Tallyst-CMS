<?php

namespace App\Controller\Admin;

use App\Entity\Menu;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class MenuCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Menu::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Izbornik')
            ->setEntityLabelInPlural('Izbornici');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Naziv');
        yield TextField::new('location', 'Lokacija')
            ->setHelp('Jedinstveni identifikator mjesta, npr. main ili footer.');
    }
}
