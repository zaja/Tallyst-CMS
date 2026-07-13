import { Controller } from '@hotwired/stimulus';

/*
 * "Import from collection" (Faza 7 K3) — a one-time PREFILL of the whole MoR unit list (+ the form
 * name/description) from a provider "container" (Dodo product collection). NOT a stored relation, NOT a live
 * sync: it fetches the collection, shows a PREVIEW of exactly what will change (name/description/options +
 * a readable list of SKIPPED products with the reason), and only on "Import" rebuilds the rows. Cancel =
 * nothing changes. Import is prefill only — the admin reviews and saves; the server save-guard is the final
 * gate.
 *
 * Lazy + non-blocking: on connect it fetches the provider's containers ONCE and reveals the button only if
 * there are any (a provider without collections → the button stays hidden). The per-unit sellability guard
 * already ran server-side (containerUnits partitions units/skipped); this only displays + applies.
 */
export default class extends Controller {
    static targets = ['button', 'panel', 'select', 'preview', 'apply'];
    static values = { containersUrl: String, unitsUrl: String, provider: String, labels: Object };

    connect() {
        this.previewData = null;
        this.loadContainers();
    }

    async loadContainers() {
        let data;
        try {
            const resp = await fetch(this.url(this.containersUrlValue), { headers: { Accept: 'application/json' } });
            data = await resp.json();
        } catch (e) {
            return; // silent — no import offered
        }
        if (!data || 'ok' !== data.status || !Array.isArray(data.containers) || 0 === data.containers.length) {
            return; // no collections / error / unconfigured → button stays hidden
        }

        const L = this.labelsValue || {};
        this.selectTarget.replaceChildren();
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = L.choose_placeholder || '—';
        this.selectTarget.appendChild(placeholder);
        data.containers.forEach((c) => {
            const opt = document.createElement('option');
            opt.value = c.id;
            const count = null != c.productsCount ? ` (${(L.products_count || '%n%').replace('%n%', c.productsCount)})` : '';
            opt.textContent = `${c.name}${count}`;
            this.selectTarget.appendChild(opt);
        });
        if (this.hasButtonTarget) {
            this.buttonTarget.classList.remove('d-none');
        }
    }

    open(event) {
        event.preventDefault();
        this.panelTarget.classList.remove('d-none');
    }

    cancel(event) {
        event.preventDefault();
        this.hidePanel();
    }

    hidePanel() {
        this.panelTarget.classList.add('d-none');
        this.previewTarget.replaceChildren();
        this.selectTarget.value = '';
        this.applyTarget.disabled = true;
        this.previewData = null;
    }

    async loadContainer() {
        const id = this.selectTarget.value;
        this.previewData = null;
        this.applyTarget.disabled = true;
        this.previewTarget.replaceChildren();
        if ('' === id) {
            return;
        }
        const L = this.labelsValue || {};

        let data;
        try {
            const resp = await fetch(`${this.url(this.unitsUrlValue)}&id=${encodeURIComponent(id)}`, { headers: { Accept: 'application/json' } });
            data = await resp.json();
        } catch (e) {
            this.showMessage(L.error);

            return;
        }
        if (!data || 'ok' !== data.status) {
            this.showMessage(L.error);

            return;
        }

        this.previewData = data;
        this.buildPreview(data);
    }

    buildPreview(data) {
        const L = this.labelsValue || {};
        const box = this.previewTarget;
        box.replaceChildren();

        const currentName = (this.formField('name') || {}).value || '';
        if (data.name && data.name !== currentName) {
            box.appendChild(this.line((L.name_change || '%old% → %new%').replaceAll('%old%', currentName || '—').replaceAll('%new%', data.name)));
        }
        if (data.description) {
            box.appendChild(this.line(L.description_set || 'Description will be set.'));
        }

        const existing = this.rowCount();
        const units = Array.isArray(data.units) ? data.units : [];
        if (0 === units.length) {
            box.appendChild(this.line(L.empty || 'Nothing to import.', 'text-warning fw-semibold'));
        } else {
            const tpl = existing > 0 ? (L.options_replace || 'Replace %n% with %m%:') : (L.options_add || 'Import %m%:');
            box.appendChild(this.line(tpl.replaceAll('%n%', String(existing)).replaceAll('%m%', String(units.length)), 'fw-semibold'));
            const ul = document.createElement('ul');
            ul.className = 'fb-mor-import-list';
            units.forEach((u) => {
                const li = document.createElement('li');
                const price = null != u.priceMajor ? ` — ${u.priceMajor} ${String(u.currency || '').toUpperCase()}` : '';
                li.textContent = `${u.name}${price}`;
                ul.appendChild(li);
            });
            box.appendChild(ul);
        }

        // ⚠ SKIPPED — readable: which product and WHY (the hard requirement).
        const skipped = Array.isArray(data.skipped) ? data.skipped : [];
        if (skipped.length > 0) {
            box.appendChild(this.line(L.skipped_title || 'Skipped (not imported):', 'fw-semibold text-muted mt-2'));
            const ul = document.createElement('ul');
            ul.className = 'fb-mor-import-list fb-mor-import-skipped';
            skipped.forEach((s) => {
                const li = document.createElement('li');
                li.textContent = `${s.name} — ${L['reason_' + s.reason] || s.reason}`;
                ul.appendChild(li);
            });
            box.appendChild(ul);
        }

        this.applyTarget.disabled = 0 === units.length;
    }

    apply(event) {
        event.preventDefault();
        const data = this.previewData;
        if (!data || !Array.isArray(data.units) || 0 === data.units.length) {
            return;
        }

        // Form name/description (only what exists — never clobber with empty).
        this.setFormField('name', data.name);
        this.setFormField('description', data.description);

        // Rebuild the unit rows (replace).
        const collection = this.element.querySelector('[data-fb-collection][data-fb-level="morUnits"]');
        const items = collection.querySelector(':scope > [data-fb-items]');
        items.querySelectorAll(':scope > [data-fb-item]').forEach((r) => r.remove());
        data.units.forEach((u, i) => this.addRow(collection, i, u));
        collection.dataset.fbIndex = String(data.units.length);

        // A single-unit import fills the FORM price/currency too (the form price line returns for 1 unit —
        // same as the manual single-unit prefill).
        if (1 === data.units.length) {
            const u = data.units[0];
            if (null != u.priceMajor) {
                this.setFormPrice(u.priceMajor);
            }
            this.setFormCurrency(u.currency || '');
        }

        this.hidePanel();
    }

    // --- row rebuild ---

    addRow(collection, index, unit) {
        const html = collection.dataset.prototype.replaceAll(collection.dataset.prototypeName, String(index));
        const tpl = document.createElement('template');
        tpl.innerHTML = html.trim();
        const row = tpl.content.firstElementChild;
        collection.querySelector(':scope > [data-fb-items]').appendChild(row);
        this.fillRow(row, unit);
    }

    fillRow(row, unit) {
        const q = (t) => row.querySelector(`[data-formbuilder--mor-unit-target="${t}"]`);
        const label = q('label');
        if (label) {
            label.value = unit.name || '';
        }
        const unitId = q('unitId');
        if (unitId) {
            this.setUnitId(unitId, unit.unitId, unit.name);
        }
        const minor = null != unit.priceMajor ? String(Math.round(parseFloat(unit.priceMajor) * 100)) : '';
        const price = q('priceMinor');
        if (price) {
            price.value = minor;
        }
        const currency = q('currency');
        if (currency) {
            currency.value = unit.currency || '';
        }
        const display = q('display');
        if (display) {
            display.textContent = this.priceText(minor, unit.currency);
        }
    }

    /** Ensure the unit id holds even if it isn't among the picker's <select> options (mirrors the server). */
    setUnitId(el, id, name) {
        if ('SELECT' === el.tagName) {
            if (!Array.from(el.options).some((o) => o.value === id)) {
                const opt = document.createElement('option');
                opt.value = id;
                opt.textContent = name || id;
                el.appendChild(opt);
            }
        }
        el.value = id;
    }

    // --- form fields ---

    formField(name) {
        const form = this.element.closest('form');
        return form ? form.querySelector(`[name="form_definition[${name}]"]`) : null;
    }

    setFormField(name, value) {
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
        if (el && currency && Array.from(el.options || []).some((o) => o.value === currency)) {
            el.value = currency;
        }
    }

    // --- misc ---

    rowCount() {
        const items = this.element.querySelector('[data-fb-collection][data-fb-level="morUnits"] > [data-fb-items]');
        return items ? items.querySelectorAll(':scope > [data-fb-item]').length : 0;
    }

    priceText(minor, currency) {
        const n = parseInt(minor, 10);
        if (Number.isNaN(n)) {
            return '—';
        }
        const cur = String(currency || '').toUpperCase();
        return `${(n / 100).toFixed(2)}${cur ? ` ${cur}` : ''}`;
    }

    line(text, cls) {
        const el = document.createElement('div');
        if (cls) {
            el.className = cls;
        }
        el.textContent = text;

        return el;
    }

    showMessage(text) {
        this.previewTarget.replaceChildren(this.line(text, 'text-danger'));
        this.applyTarget.disabled = true;
    }

    url(base) {
        if (!this.hasProviderValue || '' === this.providerValue) {
            return base;
        }
        const sep = base.includes('?') ? '&' : '?';

        return `${base}${sep}provider=${encodeURIComponent(this.providerValue)}`;
    }
}
