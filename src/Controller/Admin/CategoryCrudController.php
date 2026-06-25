<?php

namespace App\Controller\Admin;

use App\Entity\Category;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Tallyst\Media\Field\MediaPickerField;

#[IsGranted('ROLE_EDITOR')]
class CategoryCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Category::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('admin.category.entity.singular')
            ->setEntityLabelInPlural('admin.category.entity.plural')
            ->addFormTheme('@Media/admin/form/media_picker_widget.html.twig');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'admin.category.field.name');
        yield SlugField::new('slug')->setTargetFieldName('name');
        yield TextareaField::new('description', 'admin.category.field.description')->hideOnIndex();
        yield MediaPickerField::new('featuredImage', 'admin.category.field.featured_image')->hideOnIndex();
    }
}
