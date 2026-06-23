import { Controller } from '@hotwired/stimulus';

/*
 * Conditional-fields editor (admin builder). Makes the per-field "Uvjeti prikaza" rules contextual,
 * reading the OTHER fields LIVE from the DOM (no save needed):
 *   field  = dropdown of other fields (label shown, key stored)
 *   operator = type-aware (checkbox → je/nije čekirano; radio/select → jednako/nije; text/number → …)
 *   value  = dropdown of that field's options (radio/select) or an input (text/number)
 *
 * It's a CLIENT LAYER: the real {field, operator, value} inputs (the stored model) are hidden and the
 * proxies write back into them — so the submitted model + the locked evaluator are unchanged, existing
 * saved rules load by reading the real inputs, and with JS off the real inputs still work.
 *
 * Checkbox uses the existing operators: "je čekirano" = equals "1", "nije čekirano" = not_equals "1"
 * (the checkbox's checked value) — NO new operators, evaluator untouched.
 *
 * Also: client auto-key (so new fields are referencable before save) + the per-field "Uvjetni prikaz"
 * show/hide toggle.
 */

const OPERATORS_BY_TYPE = {
    checkbox: [['equals', 'je čekirano'], ['not_equals', 'nije čekirano']],
    radio: [['equals', 'jednako'], ['not_equals', 'nije jednako'], ['empty', 'prazno'], ['not_empty', 'nije prazno']],
    select: [['equals', 'jednako'], ['not_equals', 'nije jednako'], ['empty', 'prazno'], ['not_empty', 'nije prazno']],
    number: [['equals', 'jednako'], ['not_equals', 'nije jednako'], ['gt', 'veće od'], ['lt', 'manje od'], ['empty', 'prazno'], ['not_empty', 'nije prazno']],
    _text: [['equals', 'jednako'], ['not_equals', 'nije jednako'], ['contains', 'sadrži'], ['empty', 'prazno'], ['not_empty', 'nije prazno']],
};
const VALUELESS = new Set(['empty', 'not_empty']);

const opsForType = (t) => OPERATORS_BY_TYPE[t] || OPERATORS_BY_TYPE._text;

function slugify(s) {
    const out = String(s || '').toLowerCase().replace(/[^\p{L}\p{N}]+/gu, '_').replace(/^_+|_+$/g, '');
    return out || 'polje';
}

function debounce(fn, ms) {
    let t;
    return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
}

function option(value, label, selected = false) {
    const o = document.createElement('option');
    o.value = value;
    o.textContent = label;
    if (selected) o.selected = true;
    return o;
}

export default class extends Controller {
    connect() {
        this.syncDebounced = debounce(() => this.syncAll(), 60);
        this.onEdit = this.onEdit.bind(this);
        this.element.addEventListener('input', this.onEdit);
        this.element.addEventListener('change', this.onEdit);
        this.observer = new MutationObserver(() => this.syncDebounced());
        this.observer.observe(this.element, { childList: true, subtree: true });
        this.syncAll();
    }

    disconnect() {
        this.element.removeEventListener('input', this.onEdit);
        this.element.removeEventListener('change', this.onEdit);
        if (this.observer) this.observer.disconnect();
    }

    /** Per-field "Uvjetni prikaz" toggle: reveal/hide the .fb-cond block (rules stay in the DOM). */
    toggleConditions(event) {
        const row = event.target.closest('.fb-field-row');
        const cond = row && row.querySelector('.fb-cond');
        if (cond) cond.classList.toggle('d-none', !event.target.checked);
    }

    onEdit(event) {
        const t = event.target;
        if (!t || !t.matches) return;
        if (t.matches('[data-fb-key-input]')) {
            t.setAttribute('data-fb-key-dirty', '');
            t.removeAttribute('data-fb-key-auto');
        }
        if (t.matches('[data-fb-label-input],[data-fb-type-input],[data-fb-options-input],[data-fb-key-input]')) {
            this.syncDebounced();
        }
    }

    syncAll() {
        // Disconnect the observer while we mutate the proxies (else our own DOM writes re-trigger it).
        if (this.observer) this.observer.disconnect();
        try {
            this.autoKey();
            const fields = this.fieldList();
            this.element.querySelectorAll('.fb-rule').forEach((row) => this.enhanceRule(row, fields));
        } finally {
            if (this.observer) this.observer.observe(this.element, { childList: true, subtree: true });
        }
    }

    /**
     * Auto-fill field keys from labels so new fields are referencable before save. The server only
     * slugifies when the key is empty, so a client key is authoritative (no slugger mirroring needed).
     *  - empty / previously-auto keys track the label live;
     *  - server keys (non-empty, not auto) are left alone;
     *  - a manually-edited key (dirty) or a key referenced by a rule (frozen) stops tracking — so a
     *    later label rename can't break an existing rule (slug-like immutability).
     */
    autoKey() {
        const rows = [...this.element.querySelectorAll('.fb-field-row')];
        const keyEl = (r) => r.querySelector('[data-fb-key-input]');
        for (const row of rows) {
            const keyInput = keyEl(row);
            const labelInput = row.querySelector('[data-fb-label-input]');
            if (!keyInput || !labelInput) continue;
            if (keyInput.hasAttribute('data-fb-key-dirty') || keyInput.hasAttribute('data-fb-key-frozen')) continue;
            const isAuto = keyInput.hasAttribute('data-fb-key-auto');
            if ('' !== keyInput.value.trim() && !isAuto) continue; // server/manual key → leave

            const base = slugify(labelInput.value);
            const taken = rows.filter((r) => r !== row).map((r) => keyEl(r)).filter(Boolean).map((i) => i.value.trim());
            let candidate = base;
            let n = 2;
            while (taken.includes(candidate)) candidate = base + '_' + n++;
            if (keyInput.value !== candidate) keyInput.value = candidate;
            keyInput.setAttribute('data-fb-key-auto', '');
        }
    }

    fieldList() {
        return [...this.element.querySelectorAll('.fb-field-row')].map((row) => {
            const keyInput = row.querySelector('[data-fb-key-input]');
            const labelInput = row.querySelector('[data-fb-label-input]');
            const typeInput = row.querySelector('[data-fb-type-input]');
            const optionsInput = row.querySelector('[data-fb-options-input]');
            const options = optionsInput
                ? optionsInput.value.split(/\r\n|\r|\n/).map((s) => s.trim()).filter(Boolean)
                : [];
            return {
                keyInput,
                key: keyInput ? keyInput.value.trim() : '',
                label: labelInput ? labelInput.value.trim() : '',
                type: typeInput ? typeInput.value : 'text',
                options,
            };
        });
    }

    enhanceRule(row, fields) {
        const realField = row.querySelector('[data-fb-rule-field]');
        const realOp = row.querySelector('[data-fb-rule-operator]');
        const realVal = row.querySelector('[data-fb-rule-value]');
        const mount = row.querySelector('[data-fb-rule-proxy]');
        const realWrap = row.querySelector('.fb-rule-real');
        if (!realField || !realOp || !realVal || !mount) return;

        const ownerRow = row.closest('.fb-field-row');
        const ownerKey = ownerRow ? (ownerRow.querySelector('[data-fb-key-input]')?.value.trim() || '') : '';
        const choices = fields.filter((f) => f.key && f.key !== ownerKey);

        // --- field proxy
        const fieldSel = document.createElement('select');
        fieldSel.className = 'form-select form-select-sm';
        fieldSel.appendChild(option('', '— polje —'));
        for (const f of choices) fieldSel.appendChild(option(f.key, f.label || f.key));
        const current = realField.value.trim();
        if (current && !choices.some((f) => f.key === current)) {
            fieldSel.appendChild(option(current, '(obrisano: ' + current + ')')); // referenced but missing
        }
        fieldSel.value = current;

        const selField = fields.find((f) => f.key === fieldSel.value);
        const type = selField ? selField.type : 'text';
        // Referenced field's key is frozen so a later label rename can't break this rule.
        if (selField && selField.keyInput) {
            selField.keyInput.setAttribute('data-fb-key-frozen', '');
            selField.keyInput.removeAttribute('data-fb-key-auto');
        }
        if ('checkbox' === type) realVal.value = '1';

        // --- operator proxy (type-aware; stored value stays one of the 7 operators)
        const ops = opsForType(type);
        const opSel = document.createElement('select');
        opSel.className = 'form-select form-select-sm';
        for (const [op, label] of ops) opSel.appendChild(option(op, label));
        if (ops.some(([op]) => op === realOp.value)) {
            opSel.value = realOp.value;
        } else {
            opSel.value = ops[0][0];
            realOp.value = ops[0][0];
        }

        // --- value proxy (by type + operator)
        const valWrap = document.createElement('div');
        this.buildValueProxy(valWrap, type, opSel.value, selField, realVal);

        fieldSel.addEventListener('change', () => { realField.value = fieldSel.value; this.syncDebounced(); });
        opSel.addEventListener('change', () => {
            realOp.value = opSel.value;
            if ('checkbox' === type) realVal.value = '1';
            this.syncDebounced();
        });

        const grid = document.createElement('div');
        grid.className = 'fb-rule-proxy-grid';
        grid.append(fieldSel, opSel, valWrap);
        mount.replaceChildren(grid);
        mount.removeAttribute('hidden');
        if (realWrap) realWrap.classList.add('d-none');
    }

    buildValueProxy(wrap, type, op, selField, realVal) {
        if (VALUELESS.has(op) || 'checkbox' === type) return; // no value control needed

        if ('radio' === type || 'select' === type) {
            const sel = document.createElement('select');
            sel.className = 'form-select form-select-sm';
            const opts = (selField && selField.options) || [];
            if (0 === opts.length) {
                // The value list is the referenced field's own options — none defined yet → say so.
                const hint = option('', '— polje nema opcija —');
                hint.disabled = true;
                sel.appendChild(hint);
                sel.disabled = true;
                wrap.appendChild(sel);
                return;
            }
            sel.appendChild(option('', '— vrijednost —'));
            for (const o of opts) sel.appendChild(option(o, o));
            if (realVal.value && !opts.includes(realVal.value)) sel.appendChild(option(realVal.value, realVal.value));
            sel.value = realVal.value;
            sel.addEventListener('change', () => { realVal.value = sel.value; });
            wrap.appendChild(sel);
            return;
        }

        const inp = document.createElement('input');
        inp.type = 'number' === type ? 'number' : 'text';
        inp.className = 'form-control form-control-sm';
        inp.placeholder = 'vrijednost';
        inp.value = realVal.value;
        inp.addEventListener('input', () => { realVal.value = inp.value; });
        wrap.appendChild(inp);
    }
}
