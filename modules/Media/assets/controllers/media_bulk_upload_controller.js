import { Controller } from '@hotwired/stimulus';
import { createMediaFilePond } from '../filepond_factory.js';

/*
 * Bulk-upload drop zone for the Media index (the create path). Creates one Media per
 * dropped image via the shared factory + endpoint, then reloads the list once the WHOLE
 * batch has settled (every file cropped/uploaded, skipped/uploaded, or cancelled) so the
 * new rows (with auto-filled title/alt) appear.
 *
 * ⚠ A previous pass replaced this reload with an in-place AJAX table swap (no navigation,
 * kept scroll position). Reverted: that swap inserted freshly server-rendered rows that
 * never went through EasyAdmin's own one-time page-load JS init (row-click-to-edit, the
 * Delete confirmation modal), and safely re-wiring those without duplicating/breaking EA's
 * own behaviour turned into re-implementing a meaningful slice of EA's internal JS — too
 * much ongoing risk for what it bought. Plain reload, kept simple.
 *
 * ⚠ Reload is driven ONLY by onQueueSettled (the whole batch settling), NEVER a per-file
 * debounce — that was the real, previously-fixed bug: a debounce timer armed after file 1
 * finished could fire while files 2/3 were still waiting on their own crop overlay (a
 * human-paced decision, not bounded by a timer), wiping them before they ever uploaded.
 * That fix stays; only the "swap vs. reload" question changed.
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
            // hadError → at least one tile is still sitting in the pond unresolved (see
            // filepond_factory.js) — reloading would wipe that error from view before the
            // admin ever saw it, so skip the reload in that case. Successful + cancelled
            // files still reload normally (their tiles already self-removed).
            onQueueSettled: (hadError) => {
                if (!hadError) {
                    window.location.reload();
                }
            },
        });
    }

    disconnect() {
        if (this.pond) {
            this.pond.destroy();
            this.pond = null;
        }
    }
}
