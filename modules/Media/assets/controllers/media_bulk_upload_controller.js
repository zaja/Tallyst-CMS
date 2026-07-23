import { Controller } from '@hotwired/stimulus';
import { createMediaFilePond } from '../filepond_factory.js';

/*
 * Bulk-upload drop zone for the Media index (the create path). Creates one Media per
 * dropped image via the shared factory + endpoint, then refreshes the list IN PLACE
 * once the WHOLE batch has settled (every file cropped/uploaded, skipped/uploaded, or
 * cancelled) so the new rows (with auto-filled title/alt) appear.
 *
 * ⚠ In place, NOT window.location.reload() (the previous design): a full reload jumped
 * the admin back to the top of the page and briefly blanked the screen — jarring after
 * a multi-file crop session. Instead this re-fetches the SAME admin URL (so the current
 * page/sort/filter querystring is respected — never bounced back to page 1) and swaps
 * ONLY the <table class="datagrid"> + its pagination footer, leaving this upload card —
 * and any FilePond error still shown on a failed file — completely untouched.
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
            onQueueSettled: () => this.refreshList(),
        });
    }

    disconnect() {
        if (this.pond) {
            this.pond.destroy();
            this.pond = null;
        }
    }

    /**
     * Re-fetch this same admin URL and swap in only the fresh datagrid table + its
     * pagination footer. Default sort is id DESC, so a new upload lands at the top of
     * page 1 — exactly where an admin dropping files here already is; a page further
     * along the list is left alone rather than forced back to page 1. Network failure
     * is a silent no-op: the list just keeps showing its pre-upload state, which is
     * harmless (the uploaded files themselves already succeeded or FilePond is already
     * showing their error).
     */
    async refreshList() {
        let html;
        try {
            const res = await fetch(window.location.href, {
                headers: { Accept: 'text/html' },
                credentials: 'same-origin',
            });
            if (!res.ok) {
                return;
            }
            html = await res.text();
        } catch (e) {
            return;
        }

        const fresh = new DOMParser().parseFromString(html, 'text/html');
        const freshTable = fresh.querySelector('table.datagrid');
        const liveTable = document.querySelector('table.datagrid');
        if (!freshTable || !liveTable) {
            return;
        }

        const freshFooter = fresh.querySelector('.content-panel-footer');
        const liveFooter = document.querySelector('.content-panel-footer');

        liveTable.replaceWith(freshTable);

        if (freshFooter && liveFooter) {
            liveFooter.replaceWith(freshFooter);
        } else if (freshFooter && !liveFooter) {
            freshTable.insertAdjacentElement('afterend', freshFooter);
        } else if (!freshFooter && liveFooter) {
            liveFooter.remove();
        }
    }
}
