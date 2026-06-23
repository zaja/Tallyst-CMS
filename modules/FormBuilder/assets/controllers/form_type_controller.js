import { Controller } from '@hotwired/stimulus';

/*
 * Form-type toggle (UI-ONLY) on the builder edit screen. Two buttons (Besplatna / Naplatna) show/hide
 * the relevant sections — Naplata (paid) and Obavijesti (free). It's pure presentation: the buttons are
 * type="button" (nothing is submitted, so no stray form field / no allow_extra_fields change), the
 * initial state is derived in Twig from the existing isProduct(), and hidden sections keep submitting
 * their existing values — the toggle reveals/hides, it never clears price/variants. connect() reapplies
 * after Turbo body swaps so it survives navigation.
 */
export default class extends Controller {
    static targets = ['option', 'paid', 'free'];

    connect() {
        const active = this.optionTargets.find((b) => b.hasAttribute('data-active')) || this.optionTargets[0];
        if (active) {
            this.applyFor(active);
        }
    }

    select(event) {
        const btn = event.currentTarget;
        this.optionTargets.forEach((b) => b.toggleAttribute('data-active', b === btn));
        this.applyFor(btn);
    }

    applyFor(btn) {
        const paid = 'paid' === btn.dataset.fbType;
        this.paidTargets.forEach((el) => el.classList.toggle('d-none', !paid));
        this.freeTargets.forEach((el) => el.classList.toggle('d-none', paid));
    }
}
