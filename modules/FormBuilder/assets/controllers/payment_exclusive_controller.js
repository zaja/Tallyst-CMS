import { Controller } from '@hotwired/stimulus';

/*
 * Hard UI enforce on the allowedPaymentMethods checkboxes: a Merchant-of-Record method (Dodo) can't be
 * combined with a non-MoR one (Stripe/PayPal) — their tax models are incompatible on one form. When a
 * MoR box is checked, the non-MoR ones are unchecked + disabled (and vice versa). The REAL guard is the
 * server-side Callback in FormDefinitionType; this is the immediate UI mirror.
 *
 * Checkboxes carry data-mor="1" (MoR) / "0" (non-MoR). Turbo-safe: listeners bound in connect() and
 * removed in disconnect(); never caches detached refs. A pre-existing MIXED state (legacy) is NOT locked
 * (so the admin can fix it) — the server rejects saving it anyway.
 */
export default class extends Controller {
    connect() {
        this.boxes = Array.from(this.element.querySelectorAll('input[type="checkbox"][data-mor]'));
        this.onChange = this.sync.bind(this);
        this.boxes.forEach((b) => b.addEventListener('change', this.onChange));
        this.recompute(); // disable-only on load; never unchecks a saved value
    }

    disconnect() {
        (this.boxes || []).forEach((b) => b.removeEventListener('change', this.onChange));
    }

    sync(event) {
        const box = event.target;
        if (box.checked) {
            // Checking one group clears the other (user-driven → safe to uncheck conflicts).
            const isMor = '1' === box.dataset.mor;
            this.boxes.forEach((b) => {
                if (('1' === b.dataset.mor) !== isMor) {
                    b.checked = false;
                }
            });
        }
        this.recompute();
    }

    /*
     * Selecting a Dodo PRODUCT is itself a MoR signal (like ticking the Dodo box) — so it must lock out
     * Stripe/PayPal too, not just the checkbox. Fired from the dodoProductId <select> change (alongside
     * the prefill controller, which touches only the price/currency inputs → no clash). Clearing the
     * product just recomputes from the checkboxes.
     */
    productChanged(event) {
        if ('' !== event.target.value) {
            this.boxes.forEach((b) => { b.checked = '1' === b.dataset.mor; });
        }
        this.recompute();
    }

    recompute() {
        const anyMor = this.boxes.some((b) => b.checked && '1' === b.dataset.mor);
        const anyNonMor = this.boxes.some((b) => b.checked && '0' === b.dataset.mor);
        const mixed = anyMor && anyNonMor; // legacy/invalid — don't lock, let the admin resolve it

        this.boxes.forEach((b) => {
            const isMor = '1' === b.dataset.mor;
            b.disabled = !mixed && ((anyMor && !isMor) || (anyNonMor && isMor));
        });
    }
}
