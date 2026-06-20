<?php

namespace Tallyst\FormBuilder\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Tallyst\FormBuilder\Form\Model\ConditionRule;

class ConditionRuleType extends AbstractType
{
    /** Operator => label. Mirrors ConditionEvaluator's supported operators. */
    public const OPERATORS = [
        'jednako' => 'equals',
        'nije jednako' => 'not_equals',
        'sadrži' => 'contains',
        'prazno' => 'empty',
        'nije prazno' => 'not_empty',
        'veće od' => 'gt',
        'manje od' => 'lt',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('field', TextType::class, [
                'required' => false,
                'label' => 'Polje (key)',
                'attr' => ['list' => 'fb-field-keys', 'placeholder' => 'npr. drzava'],
            ])
            ->add('operator', ChoiceType::class, [
                'required' => false,
                'label' => 'Operator',
                'choices' => self::OPERATORS,
                'placeholder' => '—',
            ])
            ->add('value', TextType::class, [
                'required' => false,
                'label' => 'Vrijednost',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ConditionRule::class]);
    }
}
