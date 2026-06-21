<?php

namespace Tallyst\Media\Form\Type;

use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Tallyst\Media\Entity\Media;
use Tallyst\Media\Form\Model\BrandingData;

class BrandingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('logo', EntityType::class, [
                'class' => Media::class,
                // Labels come from Media::__toString() (title ?: originalName).
                'required' => false,
                'placeholder' => '— bez logoa (prikaži naziv sajta) —',
                'label' => 'Logo',
                'help' => 'Odaberi sliku iz medija. Prazno = naziv sajta kao tekst.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => BrandingData::class]);
    }
}
