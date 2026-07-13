import { Controller } from '@hotwired/stimulus';

/*
 * One MoR sellable-unit row (Faza 6 K3 / K3.5). Two jobs, both convenience-only (the provider is the source
 * of truth; Tallyst only DISPLAYS the price — never a live/background sync):
 *
 *  1) PREFILL — picking a unit copies its name into the row label and its price/currency into the row's
 *     display cache. When the form has EXACTLY ONE unit, it ALSO fills the FORM's name/description/price/
 *     currency (the common single-product case — behaviour as before Faza 6). Silent on pick (an active
 *     choice), matching the Faza-5 prefill. Multiple units → the form is a CHOICE, so its fields are left
 *     untouched.
 *  2) REFRESH — an on-demand button that RE-FETCHES this row's unit live, shows a diff (old → new) and asks
 *     to confirm before applying. For a single-unit form the diff/apply also covers the FORM fields. Warns
 *     if the unit turned into a subscription / usage-based / PWYW / archived one; if the provider is
 *     unreachable it says so and changes nothing.
 *
 * The shared refresh URL / provider / label strings live ONCE on the [data-mor-units] wrapper (read on
 * connect), so every row — including a freshly-added one — shares them without repeating per row.
 */
export default class extends Controller {
    static targets = ['unitId', 'label', 'priceMinor', 'currency', 'display', 'refreshButton'];

    connect() {
        const wrap = this.element.closest('[data-mor-units]');
        let labels = {};
        try {
            labels = JSON.parse((wrap && wrap.dataset.morLabels) || '{}');
        } catch (e) {
            labels = {};
        }
        this.wrap = wrap;
        this.config = {
            url: (wrap && wrap.dataset.morRefreshUrl) || '',
            provider: (wrap && wrap.dataset.morProvider) || '',
            labels,
        };
        this.updateDisplay();
        this.toggleRefresh();
    }

    /** Picking a unit fills this row (label + display cache) and, for a single-unit form, the FORM fields. */
    fill(event) {
        const option = event.target.selectedOptions && event.target.selectedOptions[0];
        if (!option) {
            return;
        }
        if (this.hasLabelTarget && option.dataset.name) {
            this.labelTarget.value = option.dataset.name;
        }
        const minor = option.dataset.priceMinor;
        const hasMinor = minor !== undefined && '' !== minor;
        this.setPrice(hasMinor ? minor : '', option.dataset.currency || '');
        this.toggleRefresh();

        if (this.isSingleUnit()) {
            this.setFormValue('name', option.dataset.name);
            this.setFormValue('description', option.dataset.description);
            this.setFormPrice(hasMinor ? (parseInt(minor, 10) / 100).toFixed(2) : null);
            this.setFormCurrency(option.dataset.currency || '');
        }
    }

    toggleRefresh() {
        if (this.hasRefreshButtonTarget) {
            this.refreshButtonTarget.classList.toggle('d-none', '' === this.unitId());
        }
    }

    unitId() {
        return this.hasUnitIdTarget ? String(this.unitIdTarget.value || '').trim() : '';
    }

    async refresh(event) {
        event.preventDefault();
        const id = this.unitId();
        if ('' === id) {
            return;
        }
        const L = this.config.labels || {};

        let query = `?id=${encodeURIComponent(id)}`;
        if ('' !== this.config.provider) {
            query += `&provider=${encodeURIComponent(this.config.provider)}`;
        }

        let data;
        try {
            const resp = await fetch(`${this.config.url}${query}`, { headers: { Accept: 'application/json' } });
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

        // A unit that turned unsellable / archived is warned about — shown but never silently applied.
        let warning = '';
        if (false === data.sellable) {
            warning = `${L.unsellable}\n\n`;
        } else if (true === data.archived) {
            warning = `${L.archived}\n\n`;
        }

        const arrow = L.arrow || '→';
        const newMinor = null != data.priceMajor ? Math.round(parseFloat(data.priceMajor) * 100) : null;
        const single = this.isSingleUnit();
        const diffs = [];

        // Row diffs (label + display price).
        if (this.hasLabelTarget && data.name && data.name !== this.labelTarget.value) {
            diffs.push(`${L.name}: "${this.labelTarget.value}" ${arrow} "${data.name}"`);
        }
        if (null != newMinor && String(newMinor) !== String(this.priceMinorTarget.value || '')) {
            diffs.push(`${L.price}: ${this.displayText()} ${arrow} ${data.priceMajor} ${(data.currency || '').toUpperCase()}`.trim());
        }

        // Single-unit form → also diff the FORM fields (name/description/price/currency).
        const fName = single ? this.formField('name') : null;
        const fDesc = single ? this.formField('description') : null;
        const fPrice = single ? this.formField('priceMinor') : null;
        const fCurrency = single ? this.formField('currency') : null;
        if (fName && data.name && data.name !== fName.value) {
            diffs.push(`${L.form_name}: "${fName.value}" ${arrow} "${data.name}"`);
        }
        if (fDesc && data.description && data.description !== fDesc.value) {
            diffs.push(`${L.form_description}: ${arrow} "${data.description}"`);
        }
        if (fPrice && null != data.priceMajor && data.priceMajor !== fPrice.value) {
            diffs.push(`${L.form_price}: ${fPrice.value || '—'} ${arrow} ${data.priceMajor}`);
        }
        if (fCurrency && data.currency && this.currencyOffered(fCurrency, data.currency) && data.currency !== fCurrency.value) {
            diffs.push(`${L.form_currency}: ${(fCurrency.value || '—').toUpperCase()} ${arrow} ${data.currency.toUpperCase()}`);
        }

        if (0 === diffs.length) {
            // With a CHOICE (>1 units) name the row so it doesn't sound like it's about the whole form.
            const upToDate = (!single && L.up_to_date_named && this.hasLabelTarget)
                ? L.up_to_date_named.replace('%option%', this.labelTarget.value)
                : L.up_to_date;
            window.alert(warning ? warning + upToDate : upToDate);

            return;
        }

        if (!window.confirm(`${warning}${L.confirm_title}\n\n${diffs.join('\n')}`)) {
            return;
        }

        // Apply — row first, then (single-unit) the form. Only non-empty provider values (never clears).
        if (this.hasLabelTarget && data.name) {
            this.labelTarget.value = data.name;
        }
        if (null != newMinor) {
            this.setPrice(String(newMinor), data.currency || '');
        }
        if (single) {
            this.setFormValue('name', data.name);
            this.setFormValue('description', data.description);
            this.setFormPrice(data.priceMajor);
            this.setFormCurrency(data.currency || '');
        }
    }

    // --- row display cache ---

    setPrice(minor, currency) {
        if (this.hasPriceMinorTarget) {
            this.priceMinorTarget.value = minor;
        }
        if (this.hasCurrencyTarget) {
            this.currencyTarget.value = currency;
        }
        this.updateDisplay();
    }

    updateDisplay() {
        if (this.hasDisplayTarget) {
            this.displayTarget.textContent = this.displayText();
        }
    }

    displayText() {
        const minor = this.hasPriceMinorTarget ? parseInt(this.priceMinorTarget.value, 10) : NaN;
        if (Number.isNaN(minor)) {
            return '—';
        }
        const currency = this.hasCurrencyTarget ? String(this.currencyTarget.value || '').toUpperCase() : '';
        return `${(minor / 100).toFixed(2)}${currency ? ` ${currency}` : ''}`;
    }

    // --- form-level fill (single-unit only) ---

    /** This row is the ONLY unit → the form represents that one product. */
    isSingleUnit() {
        return this.wrap ? this.wrap.querySelectorAll('[data-fb-item]').length === 1 : false;
    }

    formField(name) {
        const form = this.element.closest('form');
        return form ? form.querySelector(`[name="form_definition[${name}]"]`) : null;
    }

    /** Set a form text field only if the incoming value exists (fill what's there; never clear). */
    setFormValue(name, value) {
        const el = this.formField(name);
        if (el && value) {
            el.value = value;
        }
    }

    setFormPrice(major) {
        const el = this.formField('priceMinor'); // MoneyType — edits MAJOR units
        if (el && null != major) {
            el.value = major;
        }
    }

    setFormCurrency(currency) {
        const el = this.formField('currency'); // ChoiceType <select>
        if (el && currency && this.currencyOffered(el, currency)) {
            el.value = currency;
        }
    }

    currencyOffered(select, currency) {
        return Array.from(select.options || []).some((o) => o.value === currency);
    }
}
