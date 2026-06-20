<?php

namespace Tallyst\FormBuilder\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Tallyst\FormBuilder\Form\Model\FieldConditions;

class ConditionsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('action', ChoiceType::class, [
                'label' => 'Radnja',
                'choices' => ['Prikaži polje ako' => 'show', 'Sakrij polje ako' => 'hide'],
            ])
            ->add('match', ChoiceType::class, [
                'label' => 'Podudaranje',
                'choices' => ['svi uvjeti (I)' => 'all', 'bilo koji uvjet (ILI)' => 'any'],
            ])
            ->add('rules', CollectionType::class, [
                'label' => false,
                'entry_type' => ConditionRuleType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
                'prototype_name' => '__rule__',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => FieldConditions::class]);
    }
}
