<?php

namespace Tallyst\FormBuilder\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\FormType;
use Tallyst\FormBuilder\Payment\DodoProcessor;
use Tallyst\FormBuilder\Payment\MerchantOfRecordInterface;
use Tallyst\FormBuilder\Payment\PaymentProcessorRegistry;
use Tallyst\FormBuilder\Service\ShippingCatalog;
use Tallyst\FormBuilder\Service\TaxCatalog;

class FormDefinitionType extends AbstractType
{
    private const PROVIDER_LABELS = ['stripe' => 'Stripe', 'paypal' => 'PayPal', 'dodo' => 'Dodo'];

    public function __construct(
        private readonly PaymentProcessorRegistry $payments,
        private readonly DodoProcessor $dodo,
        private readonly ShippingCatalog $shipping,
        private readonly TaxCatalog $tax,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Self-billed payment methods only (Stripe/PayPal). A Merchant-of-Record provider (Dodo) is NEVER
        // a checkbox here — it's chosen via the form TYPE (digital_mor) + its Dodo product. So a physical/
        // digital form is never OFFERED Dodo in the builder (Faza 4 K3: "fizičkom se ne nudi Dodo").
        $methodChoices = [];
        foreach ($this->payments->names() as $name) {
            if ($this->isMerchantOfRecord($name)) {
                continue;
            }
            $methodChoices[self::PROVIDER_LABELS[$name] ?? ucfirst($name)] = $name;
        }

        // Faza 4 KOMAD 3: ALL config fields (shipping/countries/tax/payment/Dodo) are ALWAYS added to the
        // form now — visibility is driven by the explicit formType (a live JS show/hide + the initial
        // Twig d-none), NOT by omitting fields server-side. That's what lets the admin switch the type in
        // the builder WITHOUT losing data: a hidden field still round-trips its value (reveals/hides, never
        // clears). A field irrelevant to the current type (e.g. shipping on a Dodo form) is simply inert —
        // the front already suppresses it by type. No isMerchantOfRecordForm branch here anymore.
        $data = $builder->getData();

        // Type-locking (Faza 4 KOMAD 5): the form's PURPOSE is a WIZARD decision, not freely changed in the
        // builder — switching e.g. messages ↔ physical is really a NEW form and reopens the nonsensical
        // states we spent the phase cleaning up. So the type select offers ONLY: the digital ↔ digital_mor
        // pair (the same thing you sell, a different way to charge) stays free; messages and physical are
        // LOCKED to themselves (a single-option select). To change anything else the admin makes a new form.
        $currentType = $data instanceof FormDefinition ? $data->getFormType() : FormType::MESSAGES;
        $typeChoices = match ($currentType) {
            FormType::DIGITAL, FormType::DIGITAL_MOR => [FormType::DIGITAL, FormType::DIGITAL_MOR],
            default => [$currentType],
        };

        // Shipping choices come from the catalog — the form stores only the stable KEY (like
        // allowedPaymentMethods stores provider names), never the price. The visible label shows the
        // price too ("Express — 8,00 EUR") so the admin sees what they're offering. Currency = the form's
        // (matches the variant/checkout display); catalog prices are currency-agnostic amounts. choices are
        // keyed by key (collision-free), the display comes from choice_label. Empty catalog → no choices.
        $shippingCurrency = strtoupper(($data instanceof FormDefinition ? $data->getCurrency() : null) ?: 'eur');
        $shippingLabels = [];
        foreach ($this->shipping->all() as $method) {
            $shippingLabels[$method['key']] = sprintf(
                '%s — %s %s',
                $method['label'],
                number_format($method['priceMinor'] / 100, 2, ',', '.'),
                $shippingCurrency,
            );
        }
        $shippingKeys = array_keys($shippingLabels);
        $shippingChoices = array_combine($shippingKeys, $shippingKeys);

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
            // The EXPLICIT form type (Faza 4) — the remembered decision the builder reveal reads. A real
            // enum field (mapped), so saving through the builder sets it (closing the transient state where
            // a new form defaulted to messages). Changeable later; the reveal shows/hides sections by it.
            // EnumType (not ChoiceType) so the <option> VALUES are the enum backing strings (messages/
            // physical/digital/digital_mor) — the reveal JS reads select.value, so it must be the type, not
            // an array index. choice_label maps each case to a translation key (admin domain).
            ->add('formType', EnumType::class, [
                'class' => FormType::class,
                // Restricted to the allowed transitions (see $typeChoices) — a locked type is a single-option
                // select; the digital pair offers both. EnumType keeps the backing string as the option value.
                'choices' => $typeChoices,
                'label' => 'admin.form.def.form_type',
                'help' => 'admin.form.def.form_type_help',
                'choice_label' => static fn (FormType $t): string => 'admin.form.def.form_type_option.'.$t->value,
                // The reveal controller reads this select and shows/hides sections live on change.
                'attr' => ['data-formbuilder--formtype-target' => 'select', 'data-action' => 'change->formbuilder--formtype#apply'],
            ])
            ->add('priceMinor', MoneyType::class, [
                'required' => false,
                'currency' => false,
                'label' => 'admin.form.def.price',
                'help' => 'admin.form.def.price_help',
                // Prefill target (the Dodo product picker fills this on change; JS writes MAJOR units).
                'attr' => ['data-formbuilder--dodo-prefill-target' => 'price'],
            ])
            ->add('currency', ChoiceType::class, [
                'required' => false,
                'label' => 'admin.form.def.currency',
                // Currency codes are language-neutral labels (left untranslated).
                'choices' => ['EUR' => 'eur', 'USD' => 'usd', 'GBP' => 'gbp'],
                'placeholder' => '—',
                'attr' => ['data-formbuilder--dodo-prefill-target' => 'currency'],
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
                // data-mor flags the Merchant-of-Record method(s) for the exclusive-enforce controller.
                'choice_attr' => fn (string $name): array => ['data-mor' => $this->isMerchantOfRecord($name) ? '1' : '0'],
                'multiple' => true,
                'expanded' => true,
                'help' => 'admin.form.def.payment_methods_help',
                // SERVER enforce (the real guard — UI can be bypassed): a MoR method (Dodo) can't be
                // combined with a non-MoR one (their tax models are incompatible).
                'constraints' => [new Callback([$this, 'validateMoRExclusive'])],
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

        // Per-form shipping offer: which catalog methods to show at checkout (keys only; the buyer picks
        // one if >1). ALWAYS added now (Faza 4 K3) — visibility is type-driven (physical only); a Dodo form
        // just hides it. Empty = no delivery.
        $builder->add('shippingMethods', ChoiceType::class, [
            'required' => false,
            'label' => 'admin.form.def.shipping_methods',
            'choices' => $shippingChoices,
            // Display "label — price currency"; the stored value stays the bare key.
            'choice_label' => static fn (string $key): string => $shippingLabels[$key] ?? $key,
            'multiple' => true,
            'expanded' => true,
            'help' => 'admin.form.def.shipping_methods_help',
        ]);

        // Per-form shipping-country gate (Faza 2): the ISO alpha-2 codes this form ships to. ALWAYS in the
        // DOM (Faza 4 K3), shown by type (physical). Empty = ships everywhere. CountryType provides the
        // standard list (249, localized) and validates a forged code as "not a valid choice" for free.
        $builder->add('allowedShippingCountries', CountryType::class, [
            'required' => false,
            'label' => 'admin.form.def.shipping_countries',
            'help' => 'admin.form.def.shipping_countries_help',
            'multiple' => true,
            'expanded' => true,
        ]);

        // Per-form tax rate (Faza 3): a single dropdown offering ONLY the named catalog rates + a TAX_NONE
        // "no tax" sentinel. A new form preselects the catalog DEFAULT rate (the model transformer below maps
        // null → the default key), so a form is always bound to a CONCRETE rate. Rate labels are dynamic
        // literals (name + %); "no tax" is a translation key. ALWAYS added now (Faza 4 K3), shown by type
        // (physical/digital — a Dodo/MoR form hides it, the MoR owns tax). See PLAN-FAZA-3-POREZ.md §3.
        // A quiet "— default" tag next to the rate flagged default in Settings → Tax (visual only).
        $defaultSuffix = $this->translator->trans('admin.form.def.tax_rate_default_suffix', [], 'admin');
        $taxChoices = [];
        foreach ($this->tax->all() as $rate) {
            $label = sprintf('%s (%s%%)', $rate['name'], $rate['rate']);
            if ($rate['default']) {
                $label .= ' — '.$defaultSuffix;
            }
            $taxChoices[$label] = $rate['key'];
        }
        $taxChoices['admin.form.def.tax_rate_none'] = FormDefinition::TAX_NONE;

        $builder->add('taxRateKey', ChoiceType::class, [
            'required' => false,
            'label' => 'admin.form.def.tax_rate',
            'help' => 'admin.form.def.tax_rate_help',
            'choices' => $taxChoices,
            // No empty option: a non-required non-multiple ChoiceType would otherwise default to an
            // empty `<option value="">` (a blank artefact). The transformer guarantees a value is selected.
            'placeholder' => false,
        ]);

        // Preselect the catalog default for a NEW form: map null → the default key for display.
        $defaultKey = $this->tax->default()['key'] ?? null;
        if (null !== $defaultKey) {
            $builder->get('taxRateKey')->addModelTransformer(new CallbackTransformer(
                static fn (?string $key): ?string => $key ?? $defaultKey,
                static fn (?string $key): ?string => $key,
            ));
        }

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
            $byId = [];
            foreach ($products as $p) {
                $label = null !== $p['price'] ? $p['name'].' — '.$p['price'] : $p['name'];
                $choices[$label] = $p['id'];
                $byId[$p['id']] = $p;
            }
            // Keep a saved id selectable even if it's not in the current-mode catalogue (stale / other mode).
            if (null !== $current && !in_array($current, array_column($products, 'id'), true)) {
                $choices[$current] = $current;
            }

            $builder->add('dodoProductId', ChoiceType::class, [
                'required' => false,
                'label' => 'admin.form.def.dodo_product',
                'choices' => $choices,
                // Carry each product's price/currency on the <option> so the prefill controller can copy
                // them into the Tallyst price/currency fields on change. Empty when Dodo doesn't report
                // them (or on the stale-id fallback choice) → prefill leaves that field untouched.
                'choice_attr' => static function (string $id) use ($byId): array {
                    $p = $byId[$id] ?? null;

                    return [
                        'data-price-minor' => null !== ($p['priceMinor'] ?? null) ? (string) $p['priceMinor'] : '',
                        'data-currency' => strtolower((string) ($p['currency'] ?? '')),
                    ];
                },
                'placeholder' => '—',
                'help' => 'admin.form.def.dodo_product_help',
                // Two independent handlers on the same change: prefill (price/currency) + exclusive
                // (lock out Stripe/PayPal — a Dodo product is a MoR signal). They touch disjoint DOM.
                'attr' => ['data-action' => 'change->formbuilder--dodo-prefill#fill change->formbuilder--payment-exclusive#productChanged'],
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

    /** Is this provider a Merchant-of-Record (Dodo)? Single source of truth = the marker interface. */
    private function isMerchantOfRecord(string $name): bool
    {
        return in_array($name, $this->payments->names(), true)
            && $this->payments->get($name) instanceof MerchantOfRecordInterface;
    }

    /**
     * Server-side guard (the real enforcement — the UI can be bypassed): a NON-MoR form must NOT carry a
     * Merchant-of-Record provider (Dodo) among its self-billed methods. Faza 4 K5 — with the explicit type,
     * "fizički/digitalni ≠ MoR": Dodo is reached ONLY via the digital_mor type, never a method checkbox.
     * The old mixed / choose-explicit ambiguity checks are gone — the type is now the single source of
     * truth, Dodo isn't a self-billed choice, and offeredMethods excludes it from a non-MoR offer.
     *
     * @param string[]|null $methods
     */
    public function validateMoRExclusive(?array $methods, ExecutionContextInterface $context): void
    {
        $def = $this->rootFormDefinition($context);
        if (null === $def || $def->getFormType()->isMerchantOfRecord()) {
            return; // a MoR form's provider comes from its type, not this list
        }

        foreach ($methods ?? [] as $name) {
            if (is_string($name) && $this->isMerchantOfRecord($name)) {
                $context->buildViolation('form.payment_methods_mor_on_non_mor')->addViolation();

                return;
            }
        }
    }

    /** The FormDefinition under validation (the root form's mapped data), or null. */
    private function rootFormDefinition(ExecutionContextInterface $context): ?FormDefinition
    {
        $root = $context->getRoot();
        $data = $root instanceof \Symfony\Component\Form\FormInterface ? $root->getData() : null;

        return $data instanceof FormDefinition ? $data : null;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // All builder labels/help/choice-labels translate via the `admin` domain (inherited by the
        // nested field/variant/condition sub-forms). EA chrome stays in EasyAdminBundle.
        $resolver->setDefaults(['data_class' => FormDefinition::class, 'translation_domain' => 'admin']);
    }
}
