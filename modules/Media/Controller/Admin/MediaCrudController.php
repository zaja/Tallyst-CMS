<?php

namespace Tallyst\Media\Controller\Admin;

use App\Controller\Admin\AdminCrudPolishTrait;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tallyst\Media\Entity\Media;
use Vich\UploaderBundle\Form\Type\VichImageType;

/**
 * Media library admin: upload, browse (Liip thumbnails), edit alt/title, delete.
 * Upload uses Vich via VichImageType; the entity's Assert\Image restricts to raster
 * images ≤ 5 MB (SVG rejected).
 */
#[IsGranted('ROLE_EDITOR')]
class MediaCrudController extends AbstractCrudController
{
    use AdminCrudPolishTrait;

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Media::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $this->inlineRowActions($crud
            ->setEntityLabelInSingular('admin.media.entity.singular')
            ->setEntityLabelInPlural('admin.media.entity.plural')
            ->setDefaultSort(['id' => 'DESC'])
            // The index carries a FilePond bulk-upload panel (the create path); the old
            // single-file "new" form is gone.
            ->overrideTemplate('crud/index', '@Media/admin/index.html.twig'));
    }

    public function configureActions(Actions $actions): Actions
    {
        // Create = bulk upload on the index panel; NEW is disabled so there's no hidden
        // second single-file upload route. Edit stays for alt/title tweaks + image replace.
        // No preview (media has no public page). Back-to-list on Edit.
        return $this->addBackToListAction($this->iconOnlyRowActions($actions->disable(Action::NEW), $this->translator));
    }

    public function configureFields(string $pageName): iterable
    {
        // Upload field (forms only) — VichImageType handles the actual upload + preview.
        yield TextField::new('imageFile', 'admin.media.field.image')
            ->setFormType(VichImageType::class)
            ->setFormTypeOptions([
                'allow_delete' => false,
                'download_uri' => false,
                'image_uri' => true,
                'required' => Crud::PAGE_NEW === $pageName,
            ])
            ->onlyOnForms();

        // Thumbnail (Liip "thumb") for index/detail.
        yield ImageField::new('imageName', 'admin.media.field.preview')
            ->setTemplatePath('@Media/admin/field/thumb.html.twig')
            ->hideOnForm();

        yield TextField::new('title', 'admin.media.field.title');
        yield TextField::new('imageShortcode', 'admin.media.field.shortcode')->hideOnForm()
            ->setHelp('admin.media.help.shortcode');
        yield TextField::new('alt', 'admin.media.field.alt')->hideOnIndex();
        yield TextField::new('originalName', 'admin.media.field.file')->hideOnForm();
        yield TextField::new('dimensionsLabel', 'admin.media.field.dimensions')->hideOnForm();
        yield TextField::new('mimeType', 'admin.media.field.type')->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'admin.media.field.created_at')->hideOnForm();
    }
}
