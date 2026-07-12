import { Controller } from '@hotwired/stimulus';

/*
 * Dodo product helper on the builder. Two jobs, both convenience-only (Dodo is the source of truth,
 * Tallyst only DISPLAYS its data — never a live/background sync):
 *
 *  1) PREFILL — when the admin CHANGES the Dodo product dropdown, copy the selected product's name,
 *     description, price + currency into the Tallyst fields. Runs ONLY on change (never on connect/load),
 *     so it never overwrites a saved/edited value on re-render; the admin may freely edit afterwards.
 *
 *  2) REFRESH (Faza 5 K7) — an on-demand button that RE-FETCHES the selected product's live data from
 *     Dodo (GET /products/{id}) so the admin can pull an updated name/description/price/currency AFTER
 *     the one-time prefill. It COMPARES against the on-screen values, shows the diff (old → new per
 *     field) and asks to confirm before applying — never a silent overwrite. If nothing changed it says
 *     so; if the product turned into a subscription / usage-based / pay-what-you-want / archived product
 *     it WARNS; if Dodo is unreachable it says so and changes nothing.
 *
 * Graceful throughout: fills/applies only the values that exist (an empty Dodo field never clears the
 * admin's). A missing / non-offered currency is left untouched — better empty than wrong. The Tallyst
 * price field edits MAJOR units (the option carries MINOR units → divide by 100; the endpoint already
 * returns MAJOR).
 */
export default class extends Controller {
    static targets = ['name', 'description', 'price', 'currency', 'product', 'refreshButton'];
    static values = { url: String, labels: Object };

    connect() {
        this.toggleRefresh();
    }

    fill(event) {
        const option = event.target.selectedOptions && event.target.selectedOptions[0];
        if (!option) {
            return;
        }

        const name = option.dataset.name;
        if (this.hasNameTarget && name) {
            this.nameTarget.value = name;
        }

        const description = option.dataset.description;
        if (this.hasDescriptionTarget && description) {
            this.descriptionTarget.value = description;
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
            this.setCurrency(currency);
        }

        this.toggleRefresh();
    }

    /** Show the "refresh from Dodo" button only when a product is selected. */
    toggleRefresh() {
        if (this.hasRefreshButtonTarget) {
            this.refreshButtonTarget.classList.toggle('d-none', '' === this.productId());
        }
    }

    productId() {
        if (this.hasProductTarget) {
            return String(this.productTarget.value || '').trim();
        }
        const el = this.element.querySelector('[name$="[dodoProductId]"]');

        return el ? String(el.value || '').trim() : '';
    }

    async refresh(event) {
        event.preventDefault();
        const id = this.productId();
        if ('' === id) {
            return;
        }
        const L = this.labelsValue || {};

        let data;
        try {
            const resp = await fetch(`${this.urlValue}?id=${encodeURIComponent(id)}`, {
                headers: { Accept: 'application/json' },
            });
            data = await resp.json();
        } catch (e) {
            window.alert(L.error);

            return;
        }

        if (!data || 'error' === data.status) {
            window.alert(L.error);

            return;
        }
        if ('not_found' === data.status) {
            window.alert(L.not_found);

            return;
        }

        // A product that turned unsellable / archived is warned about — shown but never silently applied.
        let warning = '';
        if (false === data.sellable) {
            warning = `${L.unsellable}\n\n`;
        } else if (true === data.archived) {
            warning = `${L.archived}\n\n`;
        }

        // Build the field-by-field diff (only non-empty Dodo values that DIFFER from the current input).
        const diffs = [];
        const arrow = L.arrow || '→';

        if (this.hasNameTarget && data.name && data.name !== this.nameTarget.value) {
            diffs.push(`${L.name}: "${this.nameTarget.value}" ${arrow} "${data.name}"`);
        }
        if (this.hasDescriptionTarget && data.description && data.description !== this.descriptionTarget.value) {
            diffs.push(`${L.description}: ${arrow} "${data.description}"`);
        }
        if (this.hasPriceTarget && null != data.priceMajor && data.priceMajor !== this.priceTarget.value) {
            diffs.push(`${L.price}: ${this.priceTarget.value || '—'} ${arrow} ${data.priceMajor}`);
        }
        if (this.hasCurrencyTarget && data.currency && this.currencyOffered(data.currency) && data.currency !== this.currencyTarget.value) {
            diffs.push(`${L.currency}: ${(this.currencyTarget.value || '—').toUpperCase()} ${arrow} ${data.currency.toUpperCase()}`);
        }

        if (0 === diffs.length) {
            window.alert(warning ? warning + L.up_to_date : L.up_to_date);

            return;
        }

        if (!window.confirm(`${warning}${L.confirm_title}\n\n${diffs.join('\n')}`)) {
            return;
        }

        // Apply — only the fields shown in the diff (non-empty, differing).
        if (this.hasNameTarget && data.name) {
            this.nameTarget.value = data.name;
        }
        if (this.hasDescriptionTarget && data.description) {
            this.descriptionTarget.value = data.description;
        }
        if (this.hasPriceTarget && null != data.priceMajor) {
            this.priceTarget.value = data.priceMajor;
        }
        if (this.hasCurrencyTarget && data.currency) {
            this.setCurrency(data.currency);
        }
    }

    /** Set the currency ONLY if the form offers it (else leave the admin's choice). */
    setCurrency(currency) {
        if (this.currencyOffered(currency)) {
            this.currencyTarget.value = currency;
        }
    }

    currencyOffered(currency) {
        return this.hasCurrencyTarget
            && Array.from(this.currencyTarget.options).some((o) => o.value === currency);
    }
}
