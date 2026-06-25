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
            ->setEntityLabelInSingular('admin.page.entity.singular')
            ->setEntityLabelInPlural('admin.page.entity.plural')
            ->setDefaultSort(['position' => 'ASC', 'id' => 'DESC'])
            ->addFormTheme('@Media/admin/form/media_picker_widget.html.twig')
            ->addFormTheme('@Media/admin/form/tiptap_widget.html.twig');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title', 'admin.page.field.title');
        yield SlugField::new('slug')->setTargetFieldName('title');
        yield ChoiceField::new('status', 'admin.page.field.status')
            ->setChoices(['admin.page.status.draft' => Page::STATUS_DRAFT, 'admin.page.status.published' => Page::STATUS_PUBLISHED])
            ->renderAsBadges([Page::STATUS_DRAFT => 'secondary', Page::STATUS_PUBLISHED => 'success']);
        // No featured image on Pages — the hero is a Page's image. The field stays on the entity
        // (dormant) + renders null-safe in page.html.twig, so existing pages aren't affected.
        yield TiptapField::new('content', 'admin.page.field.content')->hideOnIndex();
        yield TextField::new('template', 'admin.page.field.template')->hideOnIndex()
            ->setHelp('admin.page.help.template');
        yield TextField::new('metaTitle', 'admin.page.field.meta_title')->hideOnIndex();
        yield TextareaField::new('metaDescription', 'admin.page.field.meta_description')->hideOnIndex();
        yield IntegerField::new('position', 'admin.page.field.position')->hideOnIndex();

        yield FormField::addFieldset('admin.page.fieldset.hero')->setIcon('panorama')->collapsible()
            ->setHelp('admin.page.help.hero');
        yield BooleanField::new('heroEnabled', 'admin.page.field.hero_enabled')->hideOnIndex();
        yield MediaPickerField::new('heroImage', 'admin.page.field.hero_image')->hideOnIndex();
        yield TextField::new('heroTitle', 'admin.page.field.hero_title')->hideOnIndex()
            ->setHelp('admin.page.help.hero_title');
        yield TextareaField::new('heroText', 'admin.page.field.hero_text')->hideOnIndex();
        yield TextField::new('heroCtaLabel', 'admin.page.field.hero_cta_label')->hideOnIndex();
        yield TextField::new('heroCtaUrl', 'admin.page.field.hero_cta_url')->hideOnIndex()
            ->setHelp('admin.page.help.hero_cta_url');
    }
}
