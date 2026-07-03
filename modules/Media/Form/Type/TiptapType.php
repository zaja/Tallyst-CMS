<?php

namespace Tallyst\Media\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use App\Content\EditorContentConverter;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Content field backed by the Tiptap editor. Stays a plain textarea form field (so it's
 * form-bound exactly like the old TextEditorField); the Stimulus controller mounts Tiptap
 * on top and writes the editor HTML back into the textarea on every change.
 *
 * A view transformer converts at the storage boundary via EditorContentConverter (the
 * Core aggregator of every module's shortcode<->node converter — [image], [form], …), so
 * the editor stays decoupled from any specific module. The model/DB value stays HTML +
 * shortcodes.
 */
class TiptapType extends AbstractType
{
    public function __construct(
        private readonly EditorContentConverter $converter,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addViewTransformer(new CallbackTransformer(
            fn (?string $stored): string => $this->converter->toEditorHtml($stored),
            fn (?string $editorHtml): string => $this->converter->toStored($editorHtml),
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // sticky = pin the toolbar while scrolling long content. Opt-in: TRUE only for the main
        // Page/Post content editor (via TiptapField); the small RICH_TEXT setting editors
        // (footer/top-bar/thank-you/maintenance) keep it false so their toolbars scroll normally.
        $resolver->setDefaults(['sticky' => false]);
        $resolver->setAllowedTypes('sticky', 'bool');
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['sticky'] = $options['sticky'];
    }

    public function getParent(): string
    {
        return TextareaType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'tiptap';
    }
}
