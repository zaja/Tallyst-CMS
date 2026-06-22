<?php

namespace Tallyst\Media\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Tallyst\Media\Repository\MediaRepository;

/**
 * Media picker whose value is the Media ID as a plain string — for the typed Settings layer,
 * where Media references are stored as a loose id (e.g. logo_media_id, favicon_media_id), NOT
 * as an entity/FK. Unlike MediaPickerType (model = Media entity, for EA association fields),
 * here the model AND view are the id string, so it drops straight into the string-backed
 * Setting store with no transformer.
 *
 * It REUSES the existing `media_picker` form-theme block (block prefix below) + the
 * `media--picker` Stimulus controller + the library modal — they only need the hidden field's
 * value (the id) and a `media` view var for the thumbnail preview, which buildView() resolves.
 */
class MediaIdPickerType extends AbstractType
{
    public function __construct(
        private readonly MediaRepository $media,
    ) {
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $id = $form->getData();
        $view->vars['media'] = (null !== $id && '' !== $id) ? $this->media->find((int) $id) : null;
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
