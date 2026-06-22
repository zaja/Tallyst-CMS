<?php

namespace Tallyst\FormBuilder\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * One price-variant row in the builder (a plain array {label, priceMinor}, not an entity). Price is
 * edited in major units and stored as integer minor units — same transformer as FormDefinition's
 * fixed priceMinor, never a bare float cast.
 */
class VariantType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, ['label' => 'Naziv varijante', 'empty_data' => ''])
            ->add('priceMinor', MoneyType::class, [
                'required' => false,
                'currency' => false,
                'label' => 'Cijena',
            ]);

        $builder->get('priceMinor')->addModelTransformer(new CallbackTransformer(
            static fn (?int $minor): ?float => null === $minor ? null : $minor / 100,
            static function ($major): ?int {
                if (null === $major || '' === $major) {
                    return null;
                }

                return (int) round(((float) $major) * 100);
            },
        ));
    }
}
