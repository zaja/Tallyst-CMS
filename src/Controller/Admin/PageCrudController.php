<?php

namespace App\Controller\Admin;

use App\Entity\Page;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Tallyst\Media\Field\MediaPickerField;
use Tallyst\Media\Field\TiptapField;

#[IsGranted('ROLE_EDITOR')]
class PageCrudController extends AbstractCrudController
{
    use AdminCrudPolishTrait;

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Page::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $this->inlineRowActions($crud
            ->setEntityLabelInSingular('admin.page.entity.singular')
            ->setEntityLabelInPlural('admin.page.entity.plural')
            ->setDefaultSort(['position' => 'ASC', 'id' => 'DESC'])
            ->addFormTheme('@Media/admin/form/media_picker_widget.html.twig')
            ->addFormTheme('@Media/admin/form/tiptap_widget.html.twig'));
    }

    public function configureActions(Actions $actions): Actions
    {
        // Preview the live page (home slug → "/", others → /{slug}); only for published pages.
        $actions = $this->addPreviewAction(
            $actions,
            fn (Page $p): string => 'home' === $p->getSlug()
                ? $this->urlGenerator->generate('home', [], UrlGeneratorInterface::ABSOLUTE_URL)
                : $this->urlGenerator->generate('page_show', ['slug' => $p->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL),
            static fn (Page $p): bool => $p->isPublished(),
        );

        return $this->addBackToListAction($actions);
    }

    public function configureFields(string $pageName): iterable
    {
        // Two-column form layout (new/edit only — the addColumn markers auto-hide on the index
        // list): a wide MAIN column for the content + hero, a narrow RIGHT column for the
        // lightweight settings, so the edit screen isn't one long scroll.
        yield FormField::addColumn(8);
        yield TextField::new('title', 'admin.page.field.title');
        yield SlugField::new('slug')->setTargetFieldName('title');
        // No featured image on Pages — the hero is a Page's image. The field stays on the entity
        // (dormant) + renders null-safe in page.html.twig, so existing pages aren't affected.
        yield TiptapField::new('content', 'admin.page.field.content')->hideOnIndex();

        // Hero stays in the MAIN column — its image picker + rich-text fields need the width.
        yield FormField::addFieldset('admin.page.fieldset.hero')->setIcon('panorama')->collapsible()
            ->setHelp('admin.page.help.hero');
        yield BooleanField::new('heroEnabled', 'admin.page.field.hero_enabled')->hideOnIndex();
        yield MediaPickerField::new('heroImage', 'admin.page.field.hero_image')->hideOnIndex();
        yield ChoiceField::new('heroPosition', 'admin.page.field.hero_position')->hideOnIndex()
            ->setChoices(['admin.page.hero_position.left' => 'left', 'admin.page.hero_position.right' => 'right'])
            ->setHelp('admin.page.help.hero_position');
        yield ChoiceField::new('heroStyle', 'admin.page.field.hero_style')->hideOnIndex()
            ->setChoices([
                'admin.page.hero_style.photo' => 'photo',
                'admin.page.hero_style.light' => 'light',
                'admin.page.hero_style.dark' => 'dark',
            ])
            ->setHelp('admin.page.help.hero_style');
        yield TextField::new('heroTitle', 'admin.page.field.hero_title')->hideOnIndex()
            ->setHelp('admin.page.help.hero_title');
        yield TextareaField::new('heroText', 'admin.page.field.hero_text')->hideOnIndex();
        yield TextField::new('heroCtaLabel', 'admin.page.field.hero_cta_label')->hideOnIndex();
        yield TextField::new('heroCtaUrl', 'admin.page.field.hero_cta_url')->hideOnIndex()
            ->setHelp('admin.page.help.hero_cta_url');

        // Narrow right column — lightweight settings (status badge shows on the index list).
        yield FormField::addColumn(4);
        yield ChoiceField::new('status', 'admin.page.field.status')
            ->setChoices(['admin.page.status.draft' => Page::STATUS_DRAFT, 'admin.page.status.published' => Page::STATUS_PUBLISHED])
            ->renderAsBadges([Page::STATUS_DRAFT => 'secondary', Page::STATUS_PUBLISHED => 'success']);
        yield IntegerField::new('position', 'admin.page.field.position')->hideOnIndex();
        yield BooleanField::new('hideTitle', 'admin.page.field.hide_title')->hideOnIndex()
            ->setHelp('admin.page.help.hide_title');
        yield TextField::new('template', 'admin.page.field.template')->hideOnIndex()
            ->setHelp('admin.page.help.template');
        yield TextField::new('metaTitle', 'admin.page.field.meta_title')->hideOnIndex();
        yield TextareaField::new('metaDescription', 'admin.page.field.meta_description')->hideOnIndex();
    }
}
