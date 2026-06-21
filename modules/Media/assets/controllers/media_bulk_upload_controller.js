import { Controller } from '@hotwired/stimulus';
import { createMediaFilePond } from '../filepond_factory.js';

/*
 * Bulk-upload page controller: a FilePond drop zone that creates one Media per dropped
 * image (same shared factory + endpoint as the library modal). Keeps a running count of
 * successful uploads so the admin sees progress before heading back to the Media list.
 */
export default class extends Controller {
    static targets = ['fileInput', 'count'];
    static values = {
        uploadUrl: String,
        csrfToken: String,
    };

    connect() {
        this.uploaded = 0;
        this.pond = createMediaFilePond(this.fileInputTarget, {
            uploadUrl: this.uploadUrlValue,
            csrfToken: this.csrfTokenValue,
            onProcessed: () => this.bump(),
        });
    }

    disconnect() {
        if (this.pond) {
            this.pond.destroy();
            this.pond = null;
        }
    }

    bump() {
        this.uploaded += 1;
        if (this.hasCountTarget) {
            this.countTarget.textContent = String(this.uploaded);
            this.countTarget.closest('[hidden]')?.removeAttribute('hidden');
        }
    }
}
