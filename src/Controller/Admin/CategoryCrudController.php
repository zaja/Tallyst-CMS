<?php

namespace App\Controller\Admin;

use App\Entity\Category;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tallyst\Media\Field\MediaPickerField;

#[IsGranted('ROLE_EDITOR')]
class CategoryCrudController extends AbstractCrudController
{
    use AdminCrudPolishTrait;

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Category::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $this->inlineRowActions($crud
            ->setEntityLabelInSingular('admin.category.entity.singular')
            ->setEntityLabelInPlural('admin.category.entity.plural')
            ->addFormTheme('@Media/admin/form/media_picker_widget.html.twig'));
    }

    public function configureActions(Actions $actions): Actions
    {
        // Categories have a public archive (/kategorija/{slug}) and no draft state → always previewable.
        $actions = $this->addPreviewAction(
            $actions,
            $this->translator,
            fn (Category $c): string => $this->urlGenerator->generate('category_show', ['slug' => $c->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL),
        );
        $actions = $this->iconOnlyRowActions($actions, $this->translator);

        return $this->addBackToListAction($actions);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'admin.category.field.name');
        yield SlugField::new('slug')->setTargetFieldName('name');
        yield TextareaField::new('description', 'admin.category.field.description')->hideOnIndex();
        yield MediaPickerField::new('featuredImage', 'admin.category.field.featured_image')->hideOnIndex();
    }
}
