import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';

/*
 * Admin builder UX: add/remove/reorder collection rows (fields and, nested within
 * each field, condition rules), plus collapse/expand of field rows. Pure Symfony
 * prototype mechanics — each collection carries its own data-prototype + placeholder +
 * running index, so the same `add` action serves both levels via the nearest
 * [data-fb-collection] ancestor. Field rows are collapsed by default (CSS); the row's
 * clickable summary toggles, and the label/type summary stays in sync as you edit.
 *
 * Field reorder is drag-drop (SortableJS, touch + mouse, via the .fb-handle grip) AND the
 * ↑/↓ arrows (kept as an accessible / no-Sortable fallback). Both just reorder the DOM and
 * call renumber() to rewrite the position inputs — the save mechanism is unchanged. Init does
 * NOT mutate inputs, so it never false-triggers the unsaved-changes guard on load.
 */
export default class extends Controller {
    // The "new field" summary fallback label is translated in Twig and passed as a value.
    static values = { newFieldLabel: String };

    connect() {
        // Drag-drop only the FIELDS collection (variants/rules out of scope). Newly-added rows
        // are draggable automatically since Sortable watches the container.
        const items = this.element.querySelector('[data-fb-collection][data-fb-level="fields"] > [data-fb-items]');
        if (items) {
            this.sortable = Sortable.create(items, {
                handle: '.fb-handle',
                animation: 150,
                ghostClass: 'fb-row-ghost',
                onEnd: () => this.renumber(items.closest('[data-fb-collection]')),
            });
        }
    }

    disconnect() {
        this.sortable?.destroy();
        this.sortable = null;
    }

    add(event) {
        const collection = event.currentTarget.closest('[data-fb-collection]');
        const items = collection.querySelector(':scope > [data-fb-items]');
        const placeholder = collection.dataset.prototypeName;
        let index = parseInt(collection.dataset.fbIndex || '0', 10);

        const html = collection.dataset.prototype.replaceAll(placeholder, String(index));
        index += 1;
        collection.dataset.fbIndex = String(index);

        const tmp = document.createElement('div');
        tmp.innerHTML = html.trim();
        const node = tmp.firstElementChild;
        items.appendChild(node);

        if (collection.dataset.fbLevel === 'fields') {
            // A new field opens expanded so you configure it right away; its summary
            // starts from the prototype defaults ("Novo polje" + default type).
            node.classList.remove('fb-field-row--collapsed');
            this.renumber(collection);
            this.updateSummary(node);
        }
    }

    /** Toggle a field row open/closed; refresh its summary so the head reflects edits. */
    toggle(event) {
        const row = event.currentTarget.closest('[data-fb-item]');
        if (!row) {
            return;
        }
        row.classList.toggle('fb-field-row--collapsed');
        this.updateSummary(row);
    }

    /** Live-update the collapsed summary while the label/type inputs change. */
    refreshSummary(event) {
        this.updateSummary(event.target.closest('[data-fb-item]'));
    }

    updateSummary(row) {
        if (!row) {
            return;
        }
        const labelInput = row.querySelector('[data-fb-label-input]');
        const typeSelect = row.querySelector('[data-fb-type-input]');
        const labelOut = row.querySelector('[data-fb-summary-label]');
        const typeOut = row.querySelector('[data-fb-summary-type]');
        if (labelOut && labelInput) {
            labelOut.textContent = labelInput.value.trim() || (this.newFieldLabelValue || 'Novo polje');
        }
        if (typeOut && typeSelect && typeSelect.selectedIndex >= 0) {
            typeOut.textContent = typeSelect.options[typeSelect.selectedIndex].text;
        }

        // Options textarea is only meaningful for select/radio — show/hide it by the chosen type.
        const optWrap = row.querySelector('[data-fb-options-wrap]');
        if (optWrap && typeSelect) {
            const hasOptions = 'select' === typeSelect.value || 'radio' === typeSelect.value;
            optWrap.classList.toggle('d-none', !hasOptions);
        }
    }

    remove(event) {
        const item = event.currentTarget.closest('[data-fb-item]');
        const collection = item.closest('[data-fb-collection]');
        item.remove();
        if (collection && collection.dataset.fbLevel === 'fields') {
            this.renumber(collection);
        }
    }

    moveUp(event) {
        this.move(event, -1);
    }

    moveDown(event) {
        this.move(event, 1);
    }

    move(event, direction) {
        const item = event.currentTarget.closest('[data-fb-item]');
        const sibling = direction < 0 ? item.previousElementSibling : item.nextElementSibling;
        if (!sibling || !sibling.matches('[data-fb-item]')) {
            return;
        }

        if (direction < 0) {
            item.parentNode.insertBefore(item, sibling);
        } else {
            item.parentNode.insertBefore(sibling, item);
        }

        const collection = item.closest('[data-fb-collection]');
        if (collection && collection.dataset.fbLevel === 'fields') {
            this.renumber(collection);
        }
    }

    renumber(collection) {
        const items = collection.querySelector(':scope > [data-fb-items]');
        const rows = items.querySelectorAll(':scope > [data-fb-item]');
        rows.forEach((row, i) => {
            const position = row.querySelector('[data-fb-position]');
            if (position) {
                position.value = String(i);
            }
        });
    }
}
