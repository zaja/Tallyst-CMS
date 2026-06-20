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
    private const TYPE_LABELS = [
        'Tekst' => FormField::TYPE_TEXT,
        'E-mail' => FormField::TYPE_EMAIL,
        'Dugi tekst' => FormField::TYPE_TEXTAREA,
        'Broj' => FormField::TYPE_NUMBER,
        'Padajući izbornik' => FormField::TYPE_SELECT,
        'Potvrdni okvir' => FormField::TYPE_CHECKBOX,
        'Radio' => FormField::TYPE_RADIO,
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, ['label' => 'Naziv polja', 'empty_data' => ''])
            ->add('key', TextType::class, [
                'required' => false,
                'empty_data' => '',
                'label' => 'Key',
                'help' => 'Jedinstven po formi. Prazno = generira se iz naziva.',
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'Tip',
                'choices' => self::TYPE_LABELS,
            ])
            ->add('placeholder', TextType::class, ['required' => false, 'label' => 'Placeholder'])
            ->add('required', CheckboxType::class, ['required' => false, 'label' => 'Obavezno'])
            ->add('options', TextareaType::class, [
                'required' => false,
                'label' => 'Opcije (jedna po retku)',
                'help' => 'Samo za padajući izbornik / radio.',
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
        $resolver->setDefaults(['data_class' => FormField::class]);
    }
}
