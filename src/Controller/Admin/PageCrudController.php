<?php

namespace App\Controller\Admin;

use App\Entity\Page;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
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
        // No featured image on Pages — the hero is a Page's image. The field stays on the entity
        // (dormant) + renders null-safe in page.html.twig, so existing pages aren't affected.
        yield TiptapField::new('content', 'Sadržaj')->hideOnIndex();
        yield TextField::new('template', 'Predložak')->hideOnIndex()
            ->setHelp('Naziv theme predloška, npr. page.html.twig. Prazno = zadani.');
        yield TextField::new('metaTitle', 'Meta naslov')->hideOnIndex();
        yield TextareaField::new('metaDescription', 'Meta opis')->hideOnIndex();
        yield IntegerField::new('position', 'Pozicija')->hideOnIndex();

        yield FormField::addFieldset('Hero sekcija (opcionalno)')->setIcon('panorama')->collapsible()
            ->setHelp('Hero s tekstom preko slike, na vrhu stranice. Prikazuje se samo kad je uključen i postavljena je slika ili naslov.');
        yield BooleanField::new('heroEnabled', 'Hero uključen')->hideOnIndex();
        yield MediaPickerField::new('heroImage', 'Hero slika')->hideOnIndex();
        yield TextField::new('heroTitle', 'Hero naslov')->hideOnIndex()
            ->setHelp('Prazno = koristi se naslov stranice.');
        yield TextareaField::new('heroText', 'Hero tekst')->hideOnIndex();
        yield TextField::new('heroCtaLabel', 'CTA labela')->hideOnIndex();
        yield TextField::new('heroCtaUrl', 'CTA poveznica')->hideOnIndex()
            ->setHelp('Npr. /kontakt ili https://… Gumb se prikazuje samo kad su labela I poveznica postavljene.');
    }
}
