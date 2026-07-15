<?php

namespace Tallyst\FormBuilder\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One MoR sellable-unit row in the builder (Faza 6 K3) — a plain array {label, unitId, priceMinor, currency}
 * on FormDefinition.morUnits, NOT an entity. The pandan of VariantType, but the row carries a provider UNIT
 * ID (Dodo product_id today; generic), NOT a price the admin sets: the MoR provider charges its own price, so
 * priceMinor/currency here are a DISPLAY CACHE (hidden fields the picker/refresh JS fill; they round-trip so
 * the shown price survives a save).
 *
 *  - label   : the buyer-facing option name (shown as a radio when the form has >1 unit).
 *  - unitId  : a DROPDOWN from the provider's listUnits() (via the `products` option) when available, else a
 *              plain text INPUT (manual id) — the row is ALWAYS saveable. The picker <option>s carry
 *              data-name/data-price-minor/data-currency so the row controller can prefill on change.
 *  - priceMinor/currency : hidden display cache, updated by the per-row prefill / "refresh from Dodo".
 */
class MorUnitType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('label', TextType::class, [
            'label' => 'admin.form.mor_unit.name',
            'empty_data' => '',
            'attr' => ['data-formbuilder--mor-unit-target' => 'label'],
        ]);

        $products = $options['products'];
        $data = $builder->getData();
        $current = is_array($data) ? ($data['unitId'] ?? null) : null;

        if ([] !== $products) {
            $choices = [];
            $byId = [];
            foreach ($products as $p) {
                $label = null !== $p['price'] ? $p['name'].' — '.$p['price'] : $p['name'];
                $choices[$label] = $p['id'];
                $byId[$p['id']] = $p;
            }
            // Keep a saved id selectable even if it's not in the current-mode catalogue (stale / other mode).
            if (null !== $current && '' !== $current && !in_array($current, array_column($products, 'id'), true)) {
                $choices[$current] = $current;
            }

            $builder->add('unitId', ChoiceType::class, [
                'required' => false,
                'label' => 'admin.form.mor_unit.unit',
                'choices' => $choices,
                // Carry each unit's name/description/price/currency on the <option> so the row controller can
                // prefill the label + display cache on change — and the FORM's name/description/price/currency
                // when the form has exactly one unit (Faza 6 K3.5).
                'choice_attr' => static function (string $id) use ($byId): array {
                    $p = $byId[$id] ?? null;

                    return [
                        'data-name' => (string) ($p['name'] ?? ''),
                        'data-description' => (string) ($p['description'] ?? ''),
                        'data-price-minor' => null !== ($p['priceMinor'] ?? null) ? (string) $p['priceMinor'] : '',
                        'data-currency' => strtolower((string) ($p['currency'] ?? '')),
                        // Faza 8: '1'/'0'/'' — the front's exclusive-tax note (empty = unknown → neutral).
                        'data-tax-inclusive' => isset($p['taxInclusive']) && null !== $p['taxInclusive'] ? ($p['taxInclusive'] ? '1' : '0') : '',
                        // Faza 8: pricing mode string ('by_currency'/'by_country' → the "may adjust to your region" note).
                        'data-pricing-mode' => (string) ($p['pricingMode'] ?? ''),
                    ];
                },
                'placeholder' => '—',
                'attr' => [
                    'data-formbuilder--mor-unit-target' => 'unitId',
                    'data-action' => 'change->formbuilder--mor-unit#fill',
                ],
            ]);
        } else {
            // No catalogue (unconfigured / error) → manual id entry. Refresh still works by the typed id.
            $builder->add('unitId', TextType::class, [
                'required' => false,
                'label' => 'admin.form.mor_unit.unit',
                'attr' => [
                    'data-formbuilder--mor-unit-target' => 'unitId',
                    'data-action' => 'input->formbuilder--mor-unit#toggleRefresh',
                ],
            ]);
        }

        // Display cache (minor units + currency code) — hidden fields the prefill/refresh JS writes; they
        // round-trip so the shown price survives a save. Never admin-typed (the provider charges).
        $builder->add('priceMinor', HiddenType::class, [
            'required' => false,
            'attr' => ['data-formbuilder--mor-unit-target' => 'priceMinor'],
        ]);
        $builder->add('currency', HiddenType::class, [
            'required' => false,
            'attr' => ['data-formbuilder--mor-unit-target' => 'currency'],
        ]);
        // Faza 8: tax-inclusive display cache ('1'/'0'/'') — the prefill/refresh/import JS writes it; the
        // front shows the exclusive-tax note when the buyer's provider price adds tax on top.
        $builder->add('taxInclusive', HiddenType::class, [
            'required' => false,
            'attr' => ['data-formbuilder--mor-unit-target' => 'taxInclusive'],
        ]);
        // Faza 8: pricing-mode display cache (raw string, e.g. 'by_currency') — the prefill/refresh/import JS
        // writes it; the front adds "may adjust to your region" to the inclusive note when it's localised.
        $builder->add('pricingMode', HiddenType::class, [
            'required' => false,
            'attr' => ['data-formbuilder--mor-unit-target' => 'pricingMode'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // Row labels translate via the `admin` domain (explicit; it's a collection entry type).
        $resolver->setDefaults(['translation_domain' => 'admin', 'products' => []]);
        $resolver->setAllowedTypes('products', 'array');
    }
}
