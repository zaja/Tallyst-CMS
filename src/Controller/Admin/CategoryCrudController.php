<?php

namespace App\Controller\Admin;

use App\Entity\Category;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class CategoryCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Category::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Kategorija')
            ->setEntityLabelInPlural('Kategorije');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Naziv');
        yield SlugField::new('slug')->setTargetFieldName('name');
        yield TextareaField::new('description', 'Opis')->hideOnIndex();
    }
}
