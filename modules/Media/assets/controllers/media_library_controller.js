import { Controller } from '@hotwired/stimulus';
import { createMediaFilePond } from '../filepond_factory.js';
import '../styles/media_library.css';

/*
 * Reusable media-library modal: a grid of thumbnails (Liip "thumb") + search +
 * pagination, with a FilePond upload zone that re-fetches the grid after each upload.
 *
 * DECOUPLED BY DESIGN: a click on a thumbnail dispatches a `media-library:select` event
 * carrying { id, name, thumbUrl } — it does NOT touch any hidden field. The featured
 * picker consumes that event today; the editor (Prolaz B) can reuse this same component
 * by listening for the same event. Open it by dispatching `media-library:open` on this
 * element (the controller listens for it via a data-action).
 */
export default class extends Controller {
    static targets = ['modal', 'grid', 'search', 'fileInput', 'status', 'more'];
    static values = {
        libraryUrl: String,
        uploadUrl: String,
        csrfToken: String,
    };

    connect() {
        this.page = 1;
        this.query = '';
        this.loaded = false;
        this.searchTimer = null;
        this.refetchTimer = null;

        if (this.hasFileInputTarget) {
            this.pond = createMediaFilePond(this.fileInputTarget, {
                uploadUrl: this.uploadUrlValue,
                csrfToken: this.csrfTokenValue,
                onProcessed: (id, file) => this.onUploaded(id, file),
            });
        }
    }

    disconnect() {
        if (this.pond) {
            this.pond.destroy();
            this.pond = null;
        }
        clearTimeout(this.searchTimer);
        clearTimeout(this.refetchTimer);
    }

    open() {
        this.modalTarget.hidden = false;
        document.body.style.overflow = 'hidden';
        if (!this.loaded) {
            this.reload();
        }
        if (this.hasSearchTarget) {
            this.searchTarget.focus();
        }
    }

    close() {
        this.modalTarget.hidden = true;
        document.body.style.overflow = '';
    }

    /** Close only when the backdrop itself (not the dialog) is clicked. */
    backdrop(event) {
        if (event.target === this.modalTarget) {
            this.close();
        }
    }

    search() {
        clearTimeout(this.searchTimer);
        this.searchTimer = setTimeout(() => {
            this.query = this.hasSearchTarget ? this.searchTarget.value.trim() : '';
            this.reload();
        }, 250);
    }

    /** Reset to page 1 and replace the grid. */
    reload() {
        this.page = 1;
        this.gridTarget.innerHTML = '';
        this.fetchPage(true);
    }

    loadMore() {
        this.page += 1;
        this.fetchPage(false);
    }

    async fetchPage(replace) {
        this.setStatus('Učitavanje…');
        this.toggleMore(false);

        const url = new URL(this.libraryUrlValue, window.location.origin);
        url.searchParams.set('page', String(this.page));
        if (this.query) {
            url.searchParams.set('q', this.query);
        }

        let data;
        try {
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!res.ok) {
                throw new Error('HTTP ' + res.status);
            }
            data = await res.json();
        } catch (e) {
            this.setStatus('Greška pri učitavanju biblioteke.');
            return;
        }

        this.loaded = true;
        if (replace) {
            this.gridTarget.innerHTML = '';
        }
        for (const item of data.items) {
            this.gridTarget.appendChild(this.renderItem(item));
        }

        if (replace && data.items.length === 0) {
            this.setStatus('Nema slika.');
        } else {
            this.setStatus('');
        }
        this.toggleMore(Boolean(data.hasMore));
    }

    renderItem(item) {
        const fig = document.createElement('button');
        fig.type = 'button';
        fig.className = 'media-lib__item';
        fig.dataset.action = 'click->media--library#select';
        fig.dataset.id = item.id;
        fig.dataset.name = item.name || '';
        fig.dataset.thumbUrl = item.thumbUrl || '';
        fig.title = item.name || '';

        const img = document.createElement('img');
        img.src = item.thumbUrl || '';
        img.alt = item.alt || '';
        img.loading = 'lazy';
        fig.appendChild(img);

        return fig;
    }

    select(event) {
        const el = event.currentTarget;
        this.dispatch('select', {
            prefix: 'media-library',
            detail: {
                id: el.dataset.id,
                name: el.dataset.name,
                thumbUrl: el.dataset.thumbUrl,
            },
        });
        this.close();
    }

    /** After an upload, re-fetch the grid (debounced for bulk drops) so new images show. */
    onUploaded() {
        clearTimeout(this.refetchTimer);
        this.refetchTimer = setTimeout(() => {
            this.reload();
            if (this.pond) {
                this.pond.removeFiles();
            }
        }, 400);
    }

    setStatus(text) {
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = text;
            this.statusTarget.hidden = text === '';
        }
    }

    toggleMore(show) {
        if (this.hasMoreTarget) {
            this.moreTarget.hidden = !show;
        }
    }
}
