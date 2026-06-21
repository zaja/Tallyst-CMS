<?php

namespace App\Controller\Admin;

use App\Entity\Page;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Tallyst\Media\Field\MediaPickerField;
use Tallyst\Media\Field\TiptapField;

#[IsGranted('ROLE_EDITOR')]
class PageCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Page::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Stranica')
            ->setEntityLabelInPlural('Stranice')
            ->setDefaultSort(['position' => 'ASC', 'id' => 'DESC'])
            ->addFormTheme('@Media/admin/form/media_picker_widget.html.twig')
            ->addFormTheme('@Media/admin/form/tiptap_widget.html.twig');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title', 'Naslov');
        yield SlugField::new('slug')->setTargetFieldName('title');
        yield ChoiceField::new('status', 'Status')
            ->setChoices(['Skica' => Page::STATUS_DRAFT, 'Objavljeno' => Page::STATUS_PUBLISHED])
            ->renderAsBadges([Page::STATUS_DRAFT => 'secondary', Page::STATUS_PUBLISHED => 'success']);
        yield MediaPickerField::new('featuredImage', 'Naslovna slika')->hideOnIndex();
        yield TiptapField::new('content', 'Sadržaj')->hideOnIndex();
        yield TextField::new('template', 'Predložak')->hideOnIndex()
            ->setHelp('Naziv theme predloška, npr. page.html.twig. Prazno = zadani.');
        yield TextField::new('metaTitle', 'Meta naslov')->hideOnIndex();
        yield TextareaField::new('metaDescription', 'Meta opis')->hideOnIndex();
        yield IntegerField::new('position', 'Pozicija')->hideOnIndex();
    }
}
