import { Controller } from '@hotwired/stimulus';
import { createMediaFilePond } from '../filepond_factory.js';

/*
 * Bulk-upload drop zone for the Media index (the create path). Creates one Media per
 * dropped image via the shared factory + endpoint, then reloads the list once the batch
 * settles so the new rows (with auto-filled title/alt) appear.
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
            onProcessed: () => this.scheduleReload(),
        });
    }

    disconnect() {
        clearTimeout(this.reloadTimer);
        if (this.pond) {
            this.pond.destroy();
            this.pond = null;
        }
    }

    /** Reload after the last upload settles, so a multi-file drop reloads once. */
    scheduleReload() {
        clearTimeout(this.reloadTimer);
        this.reloadTimer = setTimeout(() => window.location.reload(), 1200);
    }
}
