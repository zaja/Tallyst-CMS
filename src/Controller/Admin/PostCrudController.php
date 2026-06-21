<?php

namespace App\Controller\Admin;

use App\Entity\Post;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Tallyst\Media\Field\MediaPickerField;

class PostCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Post::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Objava')
            ->setEntityLabelInPlural('Objave')
            ->setDefaultSort(['publishedAt' => 'DESC', 'id' => 'DESC'])
            ->addFormTheme('@Media/admin/form/media_picker_widget.html.twig');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title', 'Naslov');
        yield SlugField::new('slug')->setTargetFieldName('title');
        yield ChoiceField::new('status', 'Status')
            ->setChoices(['Skica' => Post::STATUS_DRAFT, 'Objavljeno' => Post::STATUS_PUBLISHED])
            ->renderAsBadges([Post::STATUS_DRAFT => 'secondary', Post::STATUS_PUBLISHED => 'success']);
        yield AssociationField::new('category', 'Kategorija');
        yield MediaPickerField::new('featuredImage', 'Naslovna slika')->hideOnIndex();
        yield DateTimeField::new('publishedAt', 'Objavljeno')->hideOnIndex();
        yield TextareaField::new('excerpt', 'Sažetak')->hideOnIndex();
        yield TextEditorField::new('content', 'Sadržaj')->hideOnIndex();
    }
}
