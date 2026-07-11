import { Controller } from '@hotwired/stimulus';

/*
 * Makes the per-form "Ship to countries" checkbox list (CountryType, ~250 items) usable in the builder:
 * a search filter, EU / All / None presets, and a live selected-count. Pure DOM over the rendered
 * checkboxes — no data-model change (the CountryType field still submits the checked country codes).
 *
 * The country LIST comes from symfony/intl (localized labels); only the EU preset is a curated const
 * here (EU-27 alpha-2 — a preset convenience, rarely changes, NOT the country list).
 */
const EU_CODES = [
    'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE',
    'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
];

export default class extends Controller {
    static targets = ['search', 'list', 'count'];

    // "%count% selected" — translated in Twig and passed as a value (JS-i18n shim).
    static values = { countLabel: { type: String, default: '%count%' } };

    connect() {
        this.updateCount();
    }

    checkboxes() {
        return this.listTarget.querySelectorAll('input[type="checkbox"]');
    }

    /** Hide rows whose country name doesn't match the query (case-insensitive). */
    filter() {
        const q = (this.searchTarget.value || '').trim().toLowerCase();
        this.listTarget.querySelectorAll('.form-check').forEach((row) => {
            const label = row.textContent.trim().toLowerCase();
            row.classList.toggle('d-none', '' !== q && !label.includes(q));
        });
    }

    /** EU preset is ADDITIVE (checks the EU-27 on top of the current selection). */
    selectEu() {
        this.checkboxes().forEach((cb) => {
            if (EU_CODES.includes(cb.value)) {
                cb.checked = true;
            }
        });
        this.updateCount();
    }

    selectAll() {
        this.setAll(true);
    }

    selectNone() {
        this.setAll(false);
    }

    setAll(checked) {
        this.checkboxes().forEach((cb) => {
            cb.checked = checked;
        });
        this.updateCount();
    }

    /** Called on any checkbox change (delegated from the list) and by the presets. */
    updateCount() {
        if (!this.hasCountTarget) {
            return;
        }
        const n = Array.from(this.checkboxes()).filter((cb) => cb.checked).length;
        this.countTarget.textContent = this.countLabelValue.replace('%count%', String(n));
    }
}
