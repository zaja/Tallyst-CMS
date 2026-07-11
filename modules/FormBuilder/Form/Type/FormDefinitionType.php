<?php

namespace Tallyst\FormBuilder\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Payment\DodoProcessor;
use Tallyst\FormBuilder\Payment\MerchantOfRecordInterface;
use Tallyst\FormBuilder\Payment\PaymentProcessorRegistry;
use Tallyst\FormBuilder\Service\FormPaymentResolver;
use Tallyst\FormBuilder\Service\ShippingCatalog;

class FormDefinitionType extends AbstractType
{
    private const PROVIDER_LABELS = ['stripe' => 'Stripe', 'paypal' => 'PayPal', 'dodo' => 'Dodo'];

    public function __construct(
        private readonly PaymentProcessorRegistry $payments,
        private readonly DodoProcessor $dodo,
        private readonly FormPaymentResolver $paymentResolver,
        private readonly ShippingCatalog $shipping,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $methodChoices = [];
        foreach ($this->payments->names() as $name) {
            $methodChoices[self::PROVIDER_LABELS[$name] ?? ucfirst($name)] = $name;
        }

        // Shipping is meaningless on a Merchant-of-Record (Dodo) form — the MoR handles fulfilment/tax
        // itself. So the whole "Delivery methods" field is OMITTED from the form for a MoR form (same
        // isMerchantOfRecordForm signal the front/checkout use): it can't be shown, checked, or saved
        // (a forged POST for shippingMethods hits no field → ignored). Non-MoR forms keep it. Render-time
        // (on open/save); a live toggle on the Dodo checkbox isn't required. Variants are untouched here.
        $data = $builder->getData();
        $isMerchantOfRecordForm = $data instanceof FormDefinition && $this->paymentResolver->isMerchantOfRecordForm($data);

        // Shipping choices come from the catalog — the form stores only the stable KEY (like
        // allowedPaymentMethods stores provider names), never the price. The visible label shows the
        // price too ("Express — 8,00 EUR") so the admin sees what they're offering. Currency = the form's
        // (matches the variant/checkout display); catalog prices are currency-agnostic amounts. choices are
        // keyed by key (collision-free), the display comes from choice_label. Empty catalog → no choices.
        $shippingCurrency = strtoupper(($data instanceof FormDefinition ? $data->getCurrency() : null) ?: 'eur');
        $shippingLabels = [];
        if (!$isMerchantOfRecordForm) {
            foreach ($this->shipping->all() as $method) {
                $shippingLabels[$method['key']] = sprintf(
                    '%s — %s %s',
                    $method['label'],
                    number_format($method['priceMinor'] / 100, 2, ',', '.'),
                    $shippingCurrency,
                );
            }
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
        // one if >1). Mirrors allowedPaymentMethods. Empty = no delivery. OMITTED entirely on a MoR form
        // (added here, after the chain, so field order is unaffected — the template places it explicitly).
        if (!$isMerchantOfRecordForm) {
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
        }

        // Per-form shipping-country gate (Faza 2): the ISO alpha-2 codes this form ships to. Added for
        // EVERY non-MoR form (like shippingMethods) so it's ALWAYS in the DOM — the builder then shows it
        // LIVE only when a delivery method is checked (formbuilder--formtype), and the paid/free toggle
        // hides the whole card for a free form. Empty = ships everywhere. CountryType provides the standard
        // list (249, localized by the admin's locale) and validates a forged code as "not a valid choice"
        // for free. The usable UI (search + presets) is the formbuilder--country-select wrapper.
        if (!$isMerchantOfRecordForm) {
            $builder->add('allowedShippingCountries', CountryType::class, [
                'required' => false,
                'label' => 'admin.form.def.shipping_countries',
                'help' => 'admin.form.def.shipping_countries_help',
                'multiple' => true,
                'expanded' => true,
            ]);
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
     * Server-side guard: allowedPaymentMethods may not mix a Merchant-of-Record method (Dodo) with a
     * non-MoR one (Stripe/PayPal) — the inclusive-tax vs MoR models are incompatible on one form. The
     * UI mirrors this, but this is the real enforcement (the UI can be bypassed).
     *
     * Also closes the EMPTY-selection edge: empty = "offer all configured providers", which — when BOTH
     * a MoR and a non-MoR provider are configured — would silently offer a mixed model. For a PRODUCT
     * form that's ambiguous, so we require an explicit choice. A free form (no price/variants) is exempt.
     * Builder-only — the Phase-1 runtime offer / submit tax gate are NOT touched.
     *
     * @param string[]|null $methods
     */
    public function validateMoRExclusive(?array $methods, ExecutionContextInterface $context): void
    {
        $methods = $methods ?? [];

        // A non-MoR method (Stripe/PayPal) explicitly ticked.
        $hasNonMor = false;
        foreach ($methods as $name) {
            if (is_string($name) && in_array($name, $this->payments->names(), true) && !$this->isMerchantOfRecord($name)) {
                $hasNonMor = true;
            }
        }

        // The MoR side comes from the SINGLE source of truth (dodoProductId OR a MoR method) — same
        // resolver the front offer + tax-note read, so the builder can't disagree with them.
        $def = $this->rootFormDefinition($context);
        $hasMor = null !== $def && $this->paymentResolver->isMerchantOfRecordForm($def);

        if ($hasMor && $hasNonMor) {
            $context->buildViolation('form.payment_methods_mixed')->addViolation();

            return;
        }

        // Empty on a PRODUCT form + both kinds configured → ambiguous. Force an explicit choice.
        if ([] === $methods && $this->isProductForm($context) && $this->hasBothProviderKindsConfigured()) {
            $context->buildViolation('form.payment_methods_choose_explicit')->addViolation();
        }
    }

    /** The FormDefinition being validated is a product (has a price/variants) → payment methods matter. */
    private function isProductForm(ExecutionContextInterface $context): bool
    {
        $def = $this->rootFormDefinition($context);

        return null !== $def && $def->isProduct();
    }

    /** The FormDefinition under validation (the root form's mapped data), or null. */
    private function rootFormDefinition(ExecutionContextInterface $context): ?FormDefinition
    {
        $root = $context->getRoot();
        $data = $root instanceof \Symfony\Component\Form\FormInterface ? $root->getData() : null;

        return $data instanceof FormDefinition ? $data : null;
    }

    /** Are BOTH a Merchant-of-Record and a non-MoR provider currently configured (so "all" would mix)? */
    private function hasBothProviderKindsConfigured(): bool
    {
        $mor = false;
        $nonMor = false;
        foreach ($this->payments->names() as $name) {
            if (!$this->payments->get($name)->isConfigured()) {
                continue;
            }
            if ($this->isMerchantOfRecord($name)) {
                $mor = true;
            } else {
                $nonMor = true;
            }
        }

        return $mor && $nonMor;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // All builder labels/help/choice-labels translate via the `admin` domain (inherited by the
        // nested field/variant/condition sub-forms). EA chrome stays in EasyAdminBundle.
        $resolver->setDefaults(['data_class' => FormDefinition::class, 'translation_domain' => 'admin']);
    }
}
