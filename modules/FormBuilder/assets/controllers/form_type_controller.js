import { Controller } from '@hotwired/stimulus';

/*
 * Form-type toggle (UI-ONLY) on the builder edit screen. Two buttons (Besplatna / Naplatna) show/hide
 * the relevant sections — Naplata (paid) and Obavijesti (free). It's pure presentation: the buttons are
 * type="button" (nothing is submitted, so no stray form field / no allow_extra_fields change), the
 * initial state is derived in Twig (any non-empty paid config → Paid), and hidden sections keep
 * submitting their existing values — the toggle reveals/hides, it never clears price/variants. connect()
 * reapplies after Turbo body swaps so it survives navigation.
 *
 * Three live niceties (still UI-only, no data-model change):
 *  - a Merchant-of-Record (Dodo) form has no Tallyst shipping/countries, so both blocks hide LIVE when
 *    Dodo is selected (a Dodo payment method checked and/or a Dodo product chosen — the same signal the
 *    payment-exclusive controller uses) and come back when Dodo is cleared (updateShipping),
 *  - the "Ship to countries" block appears only when a delivery method is checked AND not MoR,
 *  - a gentle non-blocking note shows when Paid is active but there's no price and no variant (updateHint).
 * All recompute on any input/change in the form (delegated from the controller root). This mirrors the
 * server's isMerchantOfRecordForm omission — but only for the LIVE builder view; the backend is untouched.
 */
export default class extends Controller {
    static targets = ['option', 'paid', 'free', 'priceHint', 'countries', 'shipping', 'tax'];

    connect() {
        const active = this.optionTargets.find((b) => b.hasAttribute('data-active')) || this.optionTargets[0];
        if (active) {
            this.applyFor(active);
        }
        this.refresh();
    }

    select(event) {
        const btn = event.currentTarget;
        this.optionTargets.forEach((b) => b.toggleAttribute('data-active', b === btn));
        this.applyFor(btn);
        this.refresh();
    }

    applyFor(btn) {
        const paid = 'paid' === btn.dataset.fbType;
        this.paidActive = paid;
        this.paidTargets.forEach((el) => el.classList.toggle('d-none', !paid));
        this.freeTargets.forEach((el) => el.classList.toggle('d-none', paid));
    }

    /** Delegated from input/change on the root — keeps the live hints in sync. Cheap. */
    refresh() {
        const mor = this.isMerchantOfRecord();
        this.updateShipping(mor);
        this.updateCountries(mor);
        this.updateTax(mor);
        this.updateHint();
    }

    /**
     * Is the form CURRENTLY a Merchant-of-Record (Dodo) form? The same live signal the payment-exclusive
     * controller uses: a MoR payment method checked (data-mor="1") OR a Dodo product chosen.
     */
    isMerchantOfRecord() {
        if (this.element.querySelector('input[type="checkbox"][data-mor="1"]:checked')) {
            return true;
        }
        const product = this.element.querySelector('[name$="[dodoProductId]"]');

        return !!product && '' !== String(product.value).trim();
    }

    /** Delivery methods are meaningless on a MoR form — hide the whole block live (never cleared). */
    updateShipping(mor) {
        this.shippingTargets.forEach((el) => el.classList.toggle('d-none', mor));
    }

    /** The MoR handles its own tax — hide the per-form tax-rate block live (never cleared). */
    updateTax(mor) {
        this.taxTargets.forEach((el) => el.classList.toggle('d-none', mor));
    }

    /** Show the country block only when a delivery method is checked AND the form isn't MoR. */
    updateCountries(mor) {
        if (!this.hasCountriesTarget) {
            return;
        }
        const boxes = this.element.querySelectorAll('input[name*="[shippingMethods]"]');
        const anyChecked = Array.from(boxes).some((b) => b.checked);
        const show = !mor && anyChecked;
        this.countriesTargets.forEach((el) => el.classList.toggle('d-none', !show));
    }

    /** A gentle, non-blocking note when Paid is active but there is neither a price nor a variant. */
    updateHint() {
        if (!this.hasPriceHintTarget) {
            return;
        }
        const show = this.paidActive && !this.hasPrice() && !this.hasVariant();
        this.priceHintTarget.classList.toggle('d-none', !show);
    }

    hasPrice() {
        const el = this.element.querySelector('input[name$="[priceMinor]"]');

        return !!el && parseFloat(String(el.value).replace(',', '.')) > 0;
    }

    hasVariant() {
        const rows = this.element.querySelectorAll('[data-fb-level="variants"] [data-fb-item]');

        return Array.from(rows).some((row) => {
            const price = row.querySelector('input[name$="[priceMinor]"]');

            return !!price && parseFloat(String(price.value).replace(',', '.')) > 0;
        });
    }
}
