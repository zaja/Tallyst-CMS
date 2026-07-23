import { Controller } from '@hotwired/stimulus';
import { openCropper } from '../media_crop.js';

/*
 * KORAK 2b — cropping an EXISTING Media item, for real. The "Izreži" button on the Media
 * edit page opens the SAME Cropper.js overlay used at upload time (media_crop.js), sourced
 * from the current full-resolution stored image, but with TWO save actions instead of one
 * "Confirm crop": "Save as new image" (a brand-new Media row) and "Replace this image"
 * (crops the SAME entity's file in place — a new imageName under the hood, see
 * MediaUploader::replaceWithCrop server-side). "Replace" is the one that can surprise
 * someone (we deliberately do NOT track/count where an image is used elsewhere), so it
 * gets an extra native confirm() warning before anything is sent.
 */
export default class extends Controller {
    static targets = ['status'];
    static values = {
        sourceUrl: String,
        replaceUrl: String,
        saveNewUrl: String,
        csrfToken: String,
        replaceWarning: String,
        saving: String,
    };

    async open() {
        const outcome = await openCropper(this.sourceUrlValue, {
            allowSkip: false,
            confirmActions: [
                { action: 'saveNew', labelKey: 'save_new', fallback: 'Save as new image', variant: 'btn-secondary' },
                { action: 'replace', labelKey: 'replace', fallback: 'Replace this image', variant: 'btn-primary' },
            ],
        });

        if (!outcome || (outcome.action !== 'saveNew' && outcome.action !== 'replace')) {
            return;
        }

        if (outcome.action === 'replace' && !window.confirm(this.replaceWarningValue)) {
            return;
        }

        await this.submit(outcome.action, outcome.rect);
    }

    async submit(action, rect) {
        const url = action === 'replace' ? this.replaceUrlValue : this.saveNewUrlValue;
        this.setStatus(this.savingValue, false);

        let data;
        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': this.csrfTokenValue,
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                credentials: 'same-origin',
                body: new URLSearchParams({
                    crop_x: String(rect.x),
                    crop_y: String(rect.y),
                    crop_w: String(rect.width),
                    crop_h: String(rect.height),
                }),
            });
            data = await res.json();
            if (!res.ok) {
                this.setStatus(data.error || `HTTP ${res.status}`, true);
                return;
            }
        } catch (e) {
            this.setStatus(String(e), true);
            return;
        }

        if (data.redirect) {
            // Same-URL assignment still forces a real navigation/reload (the "Replace"
            // case redirects to this very page) — the server already flashed a success
            // message, rendered normally by the EA layout on the page that loads next.
            window.location.href = data.redirect;
        }
    }

    setStatus(text, isError) {
        if (!this.hasStatusTarget) {
            return;
        }
        this.statusTarget.textContent = text;
        this.statusTarget.classList.toggle('text-danger', isError);
        this.statusTarget.classList.toggle('text-muted', !isError);
    }
}
