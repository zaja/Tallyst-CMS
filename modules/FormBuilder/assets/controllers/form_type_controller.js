import { Controller } from '@hotwired/stimulus';

/*
 * Form-type reveal (UI-ONLY) on the builder edit screen. Faza 4 KOMAD 3: driven by the EXPLICIT formType
 * <select> (a real mapped field), NOT by guessing from price/Dodo. It shows/hides sections per the type:
 *
 *   messages     → Notifications card only (no sales card)
 *   physical     → sales: price + Stripe/PayPal + variants + tax + shipping + countries  (no Dodo)
 *   digital      → sales: price + Stripe/PayPal + variants + tax                          (no shipping/Dodo)
 *   digital_mor  → sales: price + Dodo product                                            (no self-payment/tax/shipping/variants)
 *
 * Pure presentation: every field stays in the DOM (a hidden field still round-trips its value), so switching
 * the type NEVER clears price/variants/shipping/tax/Dodo — it only changes what's active. The initial state
 * is rendered in Twig (d-none per block); this re-applies it on connect (Turbo-safe) and live on `change`.
 * The old live "Merchant-of-Record detection" (the source of past builder gaps) is gone — the type is now
 * the single, explicit source.
 */
export default class extends Controller {
    static targets = ['select', 'product', 'free', 'selfPayment', 'dodo', 'variants', 'shipping', 'countries', 'tax'];

    connect() {
        this.apply();
    }

    /** Called on connect and on the select's `change`. Reads the chosen type → toggles each section. */
    apply() {
        const type = this.hasSelectTarget ? this.selectTarget.value : 'messages';
        const isProduct = 'messages' !== type;
        const isMor = 'digital_mor' === type;
        const isPhysical = 'physical' === type;
        const isSelfBilled = isProduct && !isMor; // physical or digital → the seller collects tax

        this.toggle(this.productTargets, isProduct);
        this.toggle(this.freeTargets, !isProduct);
        this.toggle(this.selfPaymentTargets, isSelfBilled);
        this.toggle(this.dodoTargets, isMor);
        this.toggle(this.variantsTargets, isSelfBilled);
        this.toggle(this.shippingTargets, isPhysical);
        this.toggle(this.countriesTargets, isPhysical);
        this.toggle(this.taxTargets, isSelfBilled);
    }

    toggle(targets, show) {
        targets.forEach((el) => el.classList.toggle('d-none', !show));
    }
}
