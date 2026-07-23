import { Controller } from '@hotwired/stimulus';
import { createMediaFilePond } from '../filepond_factory.js';

/*
 * Bulk-upload drop zone for the Media index (the create path). Creates one Media per
 * dropped image via the shared factory + endpoint, then reloads the list once the WHOLE
 * batch has settled (every file cropped/uploaded, skipped/uploaded, or cancelled) so the
 * new rows (with auto-filled title/alt) appear. Reloading per-file (the previous design)
 * broke once cropping introduced a human-paced decision per file: a debounced reload timer
 * armed after file 1 finished could fire the full-page reload while files 2/3 were still
 * waiting on their own crop overlay, wiping them before they ever uploaded.
 */
export default class extends Controller {
    static targets = ['fileInput'];
    static values = {
        uploadUrl: String,
        csrfToken: String,
    };

    connect() {
        this.pond = createMediaFilePond(this.fileInputTarget, {
            uploadUrl: this.uploadUrlValue,
            csrfToken: this.csrfTokenValue,
            onQueueSettled: () => window.location.reload(),
        });
    }

    disconnect() {
        if (this.pond) {
            this.pond.destroy();
            this.pond = null;
        }
    }
}
