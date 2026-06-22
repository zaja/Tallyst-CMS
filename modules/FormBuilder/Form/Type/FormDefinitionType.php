<?php

namespace Tallyst\FormBuilder\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Payment\PaymentProcessorRegistry;

class FormDefinitionType extends AbstractType
{
    private const PROVIDER_LABELS = ['stripe' => 'Stripe', 'paypal' => 'PayPal'];

    public function __construct(private readonly PaymentProcessorRegistry $payments)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $methodChoices = [];
        foreach ($this->payments->names() as $name) {
            $methodChoices[self::PROVIDER_LABELS[$name] ?? ucfirst($name)] = $name;
        }

        $builder
            ->add('name', TextType::class, ['label' => 'Naziv forme', 'empty_data' => ''])
            ->add('slug', TextType::class, [
                'required' => false,
                'empty_data' => '',
                'label' => 'Slug',
                'help' => 'Prazno = generira se iz naziva.',
            ])
            ->add('description', TextareaType::class, ['required' => false, 'label' => 'Opis'])
            ->add('status', ChoiceType::class, [
                'label' => 'Status',
                'choices' => ['Skica' => FormDefinition::STATUS_DRAFT, 'Objavljeno' => FormDefinition::STATUS_PUBLISHED],
            ])
            ->add('priceMinor', MoneyType::class, [
                'required' => false,
                'currency' => false,
                'label' => 'Cijena',
                'help' => 'Prazno = forma bez naplate. S cijenom forma postaje proizvod (plaćanje na submit). Ignorira se ako postoje varijante.',
            ])
            ->add('currency', ChoiceType::class, [
                'required' => false,
                'label' => 'Valuta',
                'choices' => ['EUR' => 'eur', 'USD' => 'usd', 'GBP' => 'gbp'],
                'placeholder' => '—',
            ])
            ->add('variants', CollectionType::class, [
                'label' => false,
                'entry_type' => VariantType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
                'prototype_name' => '__variant__',
                'required' => false,
            ])
            ->add('allowedPaymentMethods', ChoiceType::class, [
                'required' => false,
                'label' => 'Dozvoljeni načini plaćanja',
                'choices' => $methodChoices,
                'multiple' => true,
                'expanded' => true,
                'help' => 'Prazno = svi konfigurirani. Kupcu se nudi presjek odabranih i konfiguriranih.',
            ])
            // Submission notification (free forms only — priced forms use order mails).
            ->add('notifyEnabled', CheckboxType::class, [
                'required' => false,
                'label' => 'Pošalji e-mail na prijavu',
                'help' => 'Samo za besplatne forme. Plaćene forme šalju potvrdu narudžbe.',
            ])
            ->add('notifyRecipient', TextType::class, [
                'required' => false,
                'label' => 'Primatelj(i)',
                'help' => 'E-mail; više njih odvoji zarezom.',
            ])
            ->add('notifySubject', TextType::class, [
                'required' => false,
                'label' => 'Subject (opcionalno)',
                'help' => 'Prazno = "Nova prijava: <naziv forme>".',
            ])
            ->add('fields', CollectionType::class, [
                'label' => false,
                'entry_type' => FormFieldType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
                'prototype_name' => '__field__',
                'required' => false,
            ]);

        // Money is stored as integer minor units on the entity; the field edits it
        // in major units. Convert with (int) round(...) — never a bare float cast.
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

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => FormDefinition::class]);
    }
}
