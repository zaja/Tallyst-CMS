<?php

namespace Tallyst\Media\Field;

use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\FieldTrait;
use Tallyst\Media\Form\Type\MediaPickerType;

/**
 * EasyAdmin field for picking a featured image from the media library. On forms it
 * renders the MediaPickerType widget (thumbnail preview + library modal); on
 * index/detail it shows the chosen image's thumbnail. The underlying property stays a
 * ManyToOne(Media) FK — this only swaps the widget for the bare AssociationField select.
 *
 * Reusable across Page/Post/Category (and the editor in Prolaz B).
 */
final class MediaPickerField implements FieldInterface
{
    use FieldTrait;

    public static function new(string $propertyName, ?string $label = null): self
    {
        return (new self())
            ->setProperty($propertyName)
            ->setLabel($label)
            ->setTemplatePath('@Media/admin/field/media_picker.html.twig')
            ->setFormType(MediaPickerType::class)
            ->addCssClass('field-media-picker');
    }
}
