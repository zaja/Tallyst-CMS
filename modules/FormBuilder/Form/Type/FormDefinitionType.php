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
use Tallyst\FormBuilder\Payment\DodoProcessor;
use Tallyst\FormBuilder\Payment\PaymentProcessorRegistry;

class FormDefinitionType extends AbstractType
{
    private const PROVIDER_LABELS = ['stripe' => 'Stripe', 'paypal' => 'PayPal', 'dodo' => 'Dodo'];

    public function __construct(
        private readonly PaymentProcessorRegistry $payments,
        private readonly DodoProcessor $dodo,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $methodChoices = [];
        foreach ($this->payments->names() as $name) {
            $methodChoices[self::PROVIDER_LABELS[$name] ?? ucfirst($name)] = $name;
        }

        $builder
            ->add('name', TextType::class, ['label' => 'admin.form.def.name', 'empty_data' => ''])
            ->add('slug', TextType::class, [
                'required' => false,
                'empty_data' => '',
                'label' => 'admin.form.def.slug',
                'help' => 'admin.form.def.slug_help',
            ])
            ->add('description', TextareaType::class, ['required' => false, 'label' => 'admin.form.def.description'])
            ->add('status', ChoiceType::class, [
                'label' => 'admin.form.def.status',
                // Choice LABELS are translation keys; stored VALUES (draft/published) are untouched.
                'choices' => ['admin.form.def.status_option.draft' => FormDefinition::STATUS_DRAFT, 'admin.form.def.status_option.published' => FormDefinition::STATUS_PUBLISHED],
            ])
            ->add('priceMinor', MoneyType::class, [
                'required' => false,
                'currency' => false,
                'label' => 'admin.form.def.price',
                'help' => 'admin.form.def.price_help',
            ])
            ->add('currency', ChoiceType::class, [
                'required' => false,
                'label' => 'admin.form.def.currency',
                // Currency codes are language-neutral labels (left untranslated).
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
                'label' => 'admin.form.def.payment_methods',
                'choices' => $methodChoices,
                'multiple' => true,
                'expanded' => true,
                'help' => 'admin.form.def.payment_methods_help',
            ])
            // Submission notification (free forms only — priced forms use order mails).
            ->add('notifyEnabled', CheckboxType::class, [
                'required' => false,
                'label' => 'admin.form.def.notify_enabled',
                'help' => 'admin.form.def.notify_enabled_help',
            ])
            ->add('notifyRecipient', TextType::class, [
                'required' => false,
                'label' => 'admin.form.def.notify_recipient',
                'help' => 'admin.form.def.notify_recipient_help',
            ])
            ->add('notifySubject', TextType::class, [
                'required' => false,
                'label' => 'admin.form.def.notify_subject',
                'help' => 'admin.form.def.notify_subject_help',
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

        $this->addDodoProductField($builder);

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

    /**
     * The Dodo (Merchant-of-Record) per-form product picker, bound to the single dodoProductId field.
     * A DROPDOWN from the live catalogue (active mode) when Dodo is configured and products load; a
     * plain text INPUT fallback otherwise (no API key / fetch error / empty catalogue) — the form is
     * ALWAYS saveable with a manually-typed product id, never locked. Both write the same field.
     */
    private function addDodoProductField(FormBuilderInterface $builder): void
    {
        $products = $this->dodo->listProducts(); // [] when unconfigured (no HTTP) or on any error
        $data = $builder->getData();
        $current = $data instanceof FormDefinition ? $data->getDodoProductId() : null;

        if ([] !== $products) {
            $choices = [];
            foreach ($products as $p) {
                $label = null !== $p['price'] ? $p['name'].' — '.$p['price'] : $p['name'];
                $choices[$label] = $p['id'];
            }
            // Keep a saved id selectable even if it's not in the current-mode catalogue (stale / other mode).
            if (null !== $current && !in_array($current, array_column($products, 'id'), true)) {
                $choices[$current] = $current;
            }

            $builder->add('dodoProductId', ChoiceType::class, [
                'required' => false,
                'label' => 'admin.form.def.dodo_product',
                'choices' => $choices,
                'placeholder' => '—',
                'help' => 'admin.form.def.dodo_product_help',
            ]);

            return;
        }

        // Fallback: manual entry over the same field. Help text distinguishes "no key" from "load failed".
        $builder->add('dodoProductId', TextType::class, [
            'required' => false,
            'label' => 'admin.form.def.dodo_product',
            'help' => $this->dodo->isConfigured()
                ? 'admin.form.def.dodo_product_help_manual'
                : 'admin.form.def.dodo_product_help_no_key',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // All builder labels/help/choice-labels translate via the `admin` domain (inherited by the
        // nested field/variant/condition sub-forms). EA chrome stays in EasyAdminBundle.
        $resolver->setDefaults(['data_class' => FormDefinition::class, 'translation_domain' => 'admin']);
    }
}
