<?php

namespace App\Controller\Admin;

use App\Entity\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class MenuItemCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return MenuItem::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Stavka izbornika')
            ->setEntityLabelInPlural('Stavke izbornika')
            ->setDefaultSort(['position' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('menu', 'Izbornik');
        yield TextField::new('label', 'Oznaka');
        yield AssociationField::new('page', 'Stranica')->hideOnIndex()
            ->setHelp('Interna stranica. Ima prednost pred URL-om ako je postavljena.');
        yield TextField::new('url', 'URL')->hideOnIndex()
            ->setHelp('Vanjski ili prilagođeni URL. Koristi se ako stranica nije odabrana.');
        yield AssociationField::new('parent', 'Roditelj')->hideOnIndex();
        yield IntegerField::new('position', 'Pozicija');
    }
}
