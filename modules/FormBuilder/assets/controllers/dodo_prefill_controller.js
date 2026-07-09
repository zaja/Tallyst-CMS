import { Controller } from '@hotwired/stimulus';

/*
 * One-time PREFILL helper on the builder payment section. When the admin CHANGES the Dodo product
 * dropdown, copy the selected product's price + currency into the Tallyst price/currency fields — a
 * convenience, NOT a live sync and NOT a lock. Runs ONLY on change (never on connect/load), so it
 * never overwrites a saved/edited value on re-render; the admin may freely edit afterwards.
 *
 * Graceful: fills only the values that exist. A missing / ambiguous (multi-currency) currency is left
 * untouched — better empty than wrong. The Tallyst price field edits MAJOR units, while the option
 * carries MINOR units, so divide by 100 (never €49 → 4900 → €4900).
 */
export default class extends Controller {
    static targets = ['price', 'currency'];

    fill(event) {
        const option = event.target.selectedOptions && event.target.selectedOptions[0];
        if (!option) {
            return;
        }

        const minor = option.dataset.priceMinor;
        if (this.hasPriceTarget && minor !== undefined && '' !== minor) {
            const parsed = parseInt(minor, 10);
            if (!Number.isNaN(parsed)) {
                this.priceTarget.value = (parsed / 100).toFixed(2);
            }
        }

        const currency = option.dataset.currency;
        if (this.hasCurrencyTarget && currency) {
            // Only set it if the form actually offers that currency (else leave the admin's choice).
            const match = Array.from(this.currencyTarget.options).some((o) => o.value === currency);
            if (match) {
                this.currencyTarget.value = currency;
            }
        }
    }
}
