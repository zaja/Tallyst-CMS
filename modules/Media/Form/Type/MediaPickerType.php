<?php

namespace Tallyst\Media\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Tallyst\Media\Entity\Media;
use Tallyst\Media\Repository\MediaRepository;

/**
 * Form type behind the featured-image picker. Maps a Media entity ↔ its id over a plain
 * hidden field (so the FK/association mapping is unchanged — only the widget differs).
 * A DataTransformer resolves the submitted id back to a managed Media; unlike EntityType
 * it doesn't load every Media into a choice list.
 *
 * The widget is rendered by the `media_picker` form theme block (thumbnail preview +
 * "open library" button + the reusable library modal).
 */
class MediaPickerType extends AbstractType
{
    public function __construct(
        private readonly MediaRepository $media,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            static fn (?Media $media): string => null !== $media && null !== $media->getId() ? (string) $media->getId() : '',
            fn (?string $id): ?Media => null !== $id && '' !== $id ? $this->media->find((int) $id) : null,
        ));
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $media = $form->getData();
        $view->vars['media'] = $media instanceof Media ? $media : null;
    }

    public function getParent(): string
    {
        return HiddenType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'media_picker';
    }
}
