<?php

namespace Tallyst\Media\Field;

use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\FieldTrait;
use Tallyst\Media\Form\Type\TiptapType;

/**
 * EasyAdmin field for rich content editing with Tiptap (replaces the Trix-based
 * TextEditorField). On forms it renders the Tiptap widget (toolbar + editor + media
 * library insert); on detail it shows the stored HTML. Storage stays HTML + shortcodes,
 * so it's a drop-in for the existing content column.
 */
final class TiptapField implements FieldInterface
{
    use FieldTrait;

    public static function new(string $propertyName, ?string $label = null): self
    {
        return (new self())
            ->setProperty($propertyName)
            ->setLabel($label)
            ->setTemplatePath('@Media/admin/field/tiptap.html.twig')
            ->setFormType(TiptapType::class)
            // TiptapField is the MAIN content editor (Page/Post content) — pin its toolbar while
            // scrolling long content. Bare TiptapType (settings RICH_TEXT) stays non-sticky.
            ->setFormTypeOption('sticky', true)
            ->addCssClass('field-tiptap');
    }
}
