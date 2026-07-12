<?php

namespace Tallyst\FormBuilder\Service;

use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Payment\MerchantOfRecordInterface;
use Tallyst\FormBuilder\Payment\PaymentProcessorRegistry;

/**
 * THE single source of truth for the form-level question "is this form a Merchant-of-Record (Dodo)
 * form, and which payment methods should it offer?". Before this, four consumers asked it four
 * different ways (some read the Dodo checkbox, some the dodoProductId, some availableFor()), so the
 * same tax-model ambiguity leaked repeatedly. Front offer, tax-note suppression and builder
 * validation all read THIS now.
 *
 * NOT the per-PAYMENT question. The submit tax gate + guards deliberately stay on the CHOSEN provider
 * (`$chosen instanceof MerchantOfRecordInterface`) — a tax decision must key on the actual money path,
 * which fails safe. This resolver answers the FORM's configuration, not one order's provider.
 *
 * "What is a MoR provider" = the MerchantOfRecordInterface marker (Phase 1). availableFor() stays the
 * low-level primitive (configured ∩ allowed); this resolver uses it for the non-MoR branch.
 */
class FormPaymentResolver
{
    public function __construct(private readonly PaymentProcessorRegistry $payments)
    {
    }

    /**
     * A form is Merchant-of-Record if its EXPLICIT type says so. Faza 4 KOMAD 2: this now reads the
     * remembered formType (DIGITAL_MOR), not the old guess (Dodo product / a MoR method among the allowed
     * ones). The type is the single source of truth for the FORM-level question. dodoProductId stays a real
     * field (WHICH Dodo product) but is no longer the type PROXY. See PLAN-FAZA-4-WIZARD.md §0, §2.
     *
     * ⚠ This is the PER-FORM signal only. The per-PAYMENT tax gate (submit: $chosen instanceof
     * MerchantOfRecordInterface) and the per-ORDER display (OrderCrudController) stay keyed on the actual
     * provider — untouched — so the Dodo money path is byte-identical.
     */
    public function isMerchantOfRecordForm(FormDefinition $form): bool
    {
        return $form->getFormType()->isMerchantOfRecord();
    }

    /**
     * The provider names to actually offer the buyer. A MoR form offers ONLY the configured MoR
     * provider(s) — ignoring any non-MoR entries in allowedPaymentMethods AND the empty="all" expansion
     * (a MoR form is MoR-only, so the front can never show Stripe/PayPal on a Dodo product). A non-MoR
     * form keeps the existing behaviour (configured ∩ allowed).
     *
     * @return string[]
     */
    public function offeredMethods(FormDefinition $form): array
    {
        if ($this->isMerchantOfRecordForm($form)) {
            // Faza 5 K2: a MoR form offers ITS chosen provider (morProvider), not "all configured MoR". With
            // Dodo the sole MoR provider today the result is IDENTICAL (a digital_mor form carries 'dodo'),
            // but this is what lets a second MoR provider (Paddle) coexist without a form offering both.
            $provider = $form->getMorProvider();
            if (null !== $provider && $this->isMerchantOfRecordProvider($provider) && $this->payments->get($provider)->isConfigured()) {
                return [$provider];
            }

            return [];
        }

        // A non-MoR form NEVER offers a Merchant-of-Record provider (Dodo) — not even when
        // allowedPaymentMethods is empty (= "all configured"). Faza 4 K5: "fizičkom/digitalnom se ne nudi
        // Dodo". Dodo is reached only via the digital_mor type, above.
        return array_values(array_filter(
            $this->payments->availableFor($form->getAllowedPaymentMethods()),
            fn (string $name): bool => !$this->isMerchantOfRecordProvider($name),
        ));
    }

    private function isMerchantOfRecordProvider(string $name): bool
    {
        return in_array($name, $this->payments->names(), true)
            && $this->payments->get($name) instanceof MerchantOfRecordInterface;
    }
}
