import { Controller } from '@hotwired/stimulus';

/*
 * Admin builder UX: add/remove/reorder collection rows (fields and, nested within
 * each field, condition rules). Pure Symfony prototype mechanics — each collection
 * carries its own data-prototype + placeholder + running index, so the same `add`
 * action serves both levels via the nearest [data-fb-collection] ancestor.
 */
export default class extends Controller {
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
            this.renumber(collection);
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
