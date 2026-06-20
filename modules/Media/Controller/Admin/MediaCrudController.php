<?php

namespace Tallyst\Media\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Tallyst\Media\Entity\Media;
use Vich\UploaderBundle\Form\Type\VichImageType;

/**
 * Media library admin: upload, browse (Liip thumbnails), edit alt/title, delete.
 * Upload uses Vich via VichImageType; the entity's Assert\Image restricts to raster
 * images ≤ 5 MB (SVG rejected).
 */
class MediaCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Media::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Medij')
            ->setEntityLabelInPlural('Mediji')
            ->setDefaultSort(['id' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        // Upload field (forms only) — VichImageType handles the actual upload + preview.
        yield TextField::new('imageFile', 'Slika')
            ->setFormType(VichImageType::class)
            ->setFormTypeOptions([
                'allow_delete' => false,
                'download_uri' => false,
                'image_uri' => true,
                'required' => Crud::PAGE_NEW === $pageName,
            ])
            ->onlyOnForms();

        // Thumbnail (Liip "thumb") for index/detail.
        yield ImageField::new('imageName', 'Pregled')
            ->setTemplatePath('@Media/admin/field/thumb.html.twig')
            ->hideOnForm();

        yield TextField::new('title', 'Naslov');
        yield TextField::new('alt', 'Alt tekst')->hideOnIndex();
        yield TextField::new('originalName', 'Datoteka')->hideOnForm();
        yield TextField::new('mimeType', 'Tip')->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'Dodano')->hideOnForm();
    }
}
