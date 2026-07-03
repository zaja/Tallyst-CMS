import { Controller } from '@hotwired/stimulus';

/*
 * Live search dropdown for the header field. As the visitor types, fetch the top 5 results from
 * /pretraga/live and show them below the input; Enter / "Prikaži sve" go to the full /pretraga page.
 *
 * The mandatory bits:
 *  - debounce ~250ms (no per-keystroke spam),
 *  - min 3 chars (matches the FULLTEXT min token),
 *  - race guard: AbortController cancels the in-flight request AND a monotonic seq token drops any
 *    response that isn't the latest (so "lic" can't overwrite "licen"),
 *  - XSS-safe render: textContent + el.href only, never innerHTML,
 *  - close on Escape / click-outside (listener bound in connect, removed in disconnect → Turbo-safe).
 *
 * The field is COLLAPSED by default (only the search icon shows); clicking the icon expands the input
 * (inline) and focuses it. Escape / click-outside collapse it again; an empty submit keeps it open
 * (never navigates to blank results). Collapse also closes the live dropdown. State is Turbo-safe:
 * the .is-expanded class is reset on disconnect, listeners bound in connect / removed in disconnect.
 */
export default class extends Controller {
    static targets = ['input', 'dropdown', 'toggleButton'];
    static values = { url: String, showAll: String };

    connect() {
        this.seq = 0;
        this.active = -1;
        this.items = [];
        this.expanded = false;
        // Keep an existing query visible on the results page (input pre-filled → start expanded) —
        // DESKTOP ONLY. On mobile the expanded field is an absolute full-width bar that would float
        // over the page content (e.g. the results title), so there it stays collapsed until tapped.
        if (this.inputTarget.value.trim() !== '' && window.matchMedia('(min-width: 52rem)').matches) {
            this.setExpanded(true);
        }
        this.onDocClick = (e) => { if (!this.element.contains(e.target)) { this.close(); this.collapse(); } };
        document.addEventListener('click', this.onDocClick);
    }

    disconnect() {
        document.removeEventListener('click', this.onDocClick);
        clearTimeout(this.timer);
        this.controllerAbort?.abort();
        this.element.classList.remove('is-expanded'); // no zombie state across a Turbo swap
    }

    /**
     * The search icon's dual role: collapsed → expand + focus (don't submit); expanded → submit,
     * EXCEPT an empty query keeps it open (never navigate to blank results).
     */
    toggle(event) {
        if (!this.expanded) {
            event.preventDefault();
            this.setExpanded(true);
            this.inputTarget.focus();
            return;
        }
        if ('' === this.inputTarget.value.trim()) {
            event.preventDefault();
            this.inputTarget.focus();
        }
        // else: a real query → let the form submit (type=submit) to /pretraga.
    }

    setExpanded(on) {
        this.expanded = on;
        this.element.classList.toggle('is-expanded', on);
        if (this.hasToggleButtonTarget) {
            this.toggleButtonTarget.setAttribute('aria-expanded', on ? 'true' : 'false');
        }
    }

    collapse() {
        if (this.expanded) {
            this.setExpanded(false);
        }
    }

    onInput() {
        clearTimeout(this.timer);
        const q = this.inputTarget.value.trim();
        if (q.length < 3) {
            this.close();
            return;
        }
        this.timer = setTimeout(() => this.fetch(q), 250);
    }

    async fetch(q) {
        const seq = ++this.seq;
        this.controllerAbort?.abort();
        this.controllerAbort = new AbortController();
        try {
            const res = await fetch(`${this.urlValue}?q=${encodeURIComponent(q)}`, {
                signal: this.controllerAbort.signal,
                headers: { Accept: 'application/json' },
            });
            if (seq !== this.seq) { return; } // a newer keystroke won — drop this stale response
            const data = await res.json();
            if (seq !== this.seq) { return; }
            this.render(data.results || [], q);
        } catch (e) {
            if ('AbortError' !== e.name) { this.close(); }
        }
    }

    render(results, q) {
        this.dropdownTarget.replaceChildren();
        this.active = -1;
        this.items = [];

        if (0 === results.length) {
            const empty = document.createElement('div');
            empty.className = 'live-search-empty';
            empty.textContent = 'Nema rezultata';
            this.dropdownTarget.appendChild(empty);
        } else {
            results.forEach((r) => {
                const a = document.createElement('a');
                a.className = 'live-search-item';
                a.href = r.url; // generated path — safe as an attribute
                const line = document.createElement('span');
                line.className = 'live-search-line';
                const badge = document.createElement('span');
                badge.className = 'live-search-type';
                badge.textContent = r.type; // textContent → never interprets HTML
                const title = document.createElement('span');
                title.className = 'live-search-title';
                title.textContent = r.title;
                line.append(badge, title);
                a.appendChild(line);
                if (r.snippet) {
                    const snippet = document.createElement('span');
                    snippet.className = 'live-search-snippet';
                    snippet.textContent = r.snippet; // plain text → XSS-safe
                    a.appendChild(snippet);
                }
                this.dropdownTarget.appendChild(a);
                this.items.push(a);
            });
        }

        const all = document.createElement('a');
        all.className = 'live-search-all';
        all.href = `${this.element.getAttribute('action')}?q=${encodeURIComponent(q)}`;
        all.textContent = this.showAllValue || 'Show all results →';
        this.dropdownTarget.appendChild(all);
        this.items.push(all);

        this.open();
    }

    keydown(event) {
        // Escape works even when the dropdown is closed: first close the dropdown, else collapse.
        if ('Escape' === event.key) {
            if (this.isOpen()) {
                this.close();
            } else {
                this.collapse();
            }
            return;
        }
        if (!this.isOpen() || 0 === this.items.length) {
            return;
        }
        if ('ArrowDown' === event.key) {
            event.preventDefault();
            this.move(1);
        } else if ('ArrowUp' === event.key) {
            event.preventDefault();
            this.move(-1);
        } else if ('Enter' === event.key && this.active >= 0) {
            event.preventDefault();
            this.items[this.active].click();
        }
    }

    move(delta) {
        this.items.forEach((el) => el.classList.remove('is-active'));
        this.active = (this.active + delta + this.items.length) % this.items.length;
        this.items[this.active].classList.add('is-active');
    }

    open() {
        this.dropdownTarget.hidden = false;
    }

    close() {
        this.dropdownTarget.hidden = true;
        this.active = -1;
    }

    isOpen() {
        return !this.dropdownTarget.hidden;
    }
}
