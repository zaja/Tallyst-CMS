import { Controller } from '@hotwired/stimulus';

/*
 * Builder reveal (Faza 6 K6): when a MoR form has MORE THAN ONE sellable unit, the form-level Price +
 * Currency fields are meaningless — each option carries its own price and the provider charges it. So hide
 * them (and show a short note) whenever the morUnits list has >1 rows, live as rows are added/removed. Only
 * MoR forms have morUnits, so a self-billed form (0 rows) is never affected. HIDES, never clears — the values
 * round-trip and reappear if the list drops back to a single unit (same "reveal, never delete" rule as the
 * type-driven reveal).
 */
export default class extends Controller {
    static targets = ['priceBlock', 'priceNote'];

    connect() {
        this.items = this.element.querySelector('[data-mor-units] [data-fb-items]');
        if (this.items) {
            this.observer = new MutationObserver(() => this.apply());
            this.observer.observe(this.items, { childList: true });
        }
        this.apply();
    }

    disconnect() {
        if (this.observer) {
            this.observer.disconnect();
        }
    }

    apply() {
        const count = this.items ? this.items.querySelectorAll(':scope > [data-fb-item]').length : 0;
        const multi = count > 1;
        if (this.hasPriceBlockTarget) {
            this.priceBlockTarget.classList.toggle('d-none', multi);
        }
        if (this.hasPriceNoteTarget) {
            this.priceNoteTarget.classList.toggle('d-none', !multi);
        }
    }
}
