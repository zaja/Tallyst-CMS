<?php

namespace Tallyst\FormBuilder\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Tallyst\FormBuilder\Entity\FormField;
use Tallyst\FormBuilder\Form\DataTransformer\ConditionsTransformer;

class FormFieldType extends AbstractType
{
    // Choice LABELS are `admin`-domain translation keys; stored VALUES (the FormField type
    // constants) are untouched — translating display never changes the saved model.
    private const TYPE_LABELS = [
        'admin.form.field.type_option.text' => FormField::TYPE_TEXT,
        'admin.form.field.type_option.email' => FormField::TYPE_EMAIL,
        'admin.form.field.type_option.textarea' => FormField::TYPE_TEXTAREA,
        'admin.form.field.type_option.number' => FormField::TYPE_NUMBER,
        'admin.form.field.type_option.select' => FormField::TYPE_SELECT,
        'admin.form.field.type_option.checkbox' => FormField::TYPE_CHECKBOX,
        'admin.form.field.type_option.radio' => FormField::TYPE_RADIO,
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, ['label' => 'admin.form.field.label', 'empty_data' => ''])
            ->add('key', TextType::class, [
                'required' => false,
                'empty_data' => '',
                'label' => 'admin.form.field.key',
                'help' => 'admin.form.field.key_help',
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'admin.form.field.type',
                'choices' => self::TYPE_LABELS,
            ])
            ->add('placeholder', TextType::class, ['required' => false, 'label' => 'admin.form.field.placeholder'])
            ->add('required', CheckboxType::class, ['required' => false, 'label' => 'admin.form.field.required'])
            ->add('options', TextareaType::class, [
                'required' => false,
                'label' => 'admin.form.field.options',
                'help' => 'admin.form.field.options_help',
            ])
            ->add('position', HiddenType::class)
            ->add('conditions', ConditionsType::class, ['label' => false]);

        // Options: textarea (one per line) <-> string[] on the entity.
        $builder->get('options')->addModelTransformer(new CallbackTransformer(
            static fn (?array $options): string => implode("\n", $options ?? []),
            static function (?string $text): array {
                if (null === $text || '' === trim($text)) {
                    return [];
                }
                $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];

                return array_values(array_filter(array_map('trim', $lines), static fn (string $l): bool => '' !== $l));
            },
        ));

        // Conditions: stored JSON array <-> editable FieldConditions DTO.
        $builder->get('conditions')->addModelTransformer(new ConditionsTransformer());
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => FormField::class, 'translation_domain' => 'admin']);
    }
}
