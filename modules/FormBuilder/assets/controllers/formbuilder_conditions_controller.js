import { Controller } from '@hotwired/stimulus';
import { visibleKeys } from '../condition_evaluator.js';

/*
 * Live field show/hide for rendered forms. Reads the SAME schema the server uses
 * and runs the SAME cycle-guarded evaluator (condition_evaluator.js). Hidden fields
 * are disabled (so they are not submitted) and de-required (so the browser does not
 * block submit) — exactly mirroring the server, which drops hidden fields too.
 */
export default class extends Controller {
    static values = { schema: Array };

    connect() {
        this.onChange = this.onChange.bind(this);
        this.element.addEventListener('input', this.onChange);
        this.element.addEventListener('change', this.onChange);
        this.apply();
    }

    disconnect() {
        this.element.removeEventListener('input', this.onChange);
        this.element.removeEventListener('change', this.onChange);
    }

    onChange() {
        this.apply();
    }

    apply() {
        const schema = this.schemaValue;
        const values = {};
        for (const field of schema) {
            values[field.key] = this.readField(field);
        }

        const visible = new Set(visibleKeys(schema, values));

        for (const field of schema) {
            const wrapper = this.element.querySelector(`[data-field-key="${CSS.escape(field.key)}"]`);
            if (!wrapper) continue;

            const isVisible = visible.has(field.key);
            wrapper.hidden = !isVisible;

            wrapper.querySelectorAll('input, select, textarea').forEach((el) => {
                el.disabled = !isVisible;
                if (isVisible && field.required) {
                    el.setAttribute('required', '');
                } else {
                    el.removeAttribute('required');
                }
            });
        }
    }

    readField(field) {
        const inputs = this.element.querySelectorAll(`[name="${CSS.escape(field.key)}"]`);
        if (inputs.length === 0) return null;

        if (field.type === 'checkbox') {
            return inputs[0].checked ? '1' : false;
        }
        if (field.type === 'radio') {
            for (const input of inputs) {
                if (input.checked) return input.value;
            }
            return '';
        }
        return inputs[0].value;
    }
}
