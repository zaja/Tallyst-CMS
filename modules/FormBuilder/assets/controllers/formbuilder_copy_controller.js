import { Controller } from '@hotwired/stimulus';

/*
 * Copies a licence key to the clipboard.
 *
 * ⚠ THIS IS A FRONT CONTROLLER — registered in front_bootstrap.js, never in the admin bootstrap.
 * It is the second controller the public site loads, and it stays dependency-free for that reason:
 * the whole point of the split bootstraps is that a visitor never downloads the editor bundle.
 *
 * ⚠ PROGRESSIVE ENHANCEMENT IS NOT OPTIONAL HERE. Somebody who lost their confirmation e-mail is on
 * this page precisely to recover their key, so it is rendered as plain selectable text and this only
 * saves them a drag of the mouse. If the clipboard is unavailable — an insecure context, a browser
 * that refuses, a permission denied — the key is SELECTED instead, so the copy is one keystroke away
 * rather than impossible.
 *
 * ⚠ Turbo swaps <body> on navigation. The revert timer is cleared in disconnect(), or it fires
 * against a button that is no longer in the document.
 */
export default class extends Controller {
    static targets = ['value', 'button'];
    static values = { done: String };

    connect() {
        this.originalLabel = this.hasButtonTarget ? this.buttonTarget.textContent : '';
        this.timer = null;
    }

    disconnect() {
        if (this.timer) {
            clearTimeout(this.timer);
            this.timer = null;
        }
    }

    async copy() {
        const text = this.hasValueTarget ? this.valueTarget.textContent.trim() : '';
        if (!text) {
            return;
        }

        try {
            // Only available in a secure context; throws (or is undefined) otherwise.
            await navigator.clipboard.writeText(text);
            this.confirm();
        } catch {
            this.selectInstead();
        }
    }

    /** Tell the person it worked, then put the button back the way it was. */
    confirm() {
        if (!this.hasButtonTarget) {
            return;
        }

        this.buttonTarget.textContent = this.doneValue || this.originalLabel;
        if (this.timer) {
            clearTimeout(this.timer);
        }
        this.timer = setTimeout(() => {
            this.buttonTarget.textContent = this.originalLabel;
            this.timer = null;
        }, 2000);
    }

    /** Fallback: highlight the key so the reader can copy it themselves. */
    selectInstead() {
        if (!this.hasValueTarget) {
            return;
        }

        const range = document.createRange();
        range.selectNodeContents(this.valueTarget);

        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
    }
}
