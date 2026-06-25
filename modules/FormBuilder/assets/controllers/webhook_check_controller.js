import { Controller } from '@hotwired/stimulus';

/*
 * Readiness panel: on-demand webhook 401 self-test. POSTs to the FormBuilder check endpoint (CSRF
 * header) and renders the per-provider verdicts. Honest statuses straight from the server
 * (ok / problem / manual) — it never invents a result. XSS-safe: all server data is written via
 * textContent / DOM nodes, never innerHTML.
 */
export default class extends Controller {
    static targets = ['button', 'results'];
    static values = { url: String, csrf: String };

    async run() {
        this.buttonTarget.disabled = true;
        this.resultsTarget.replaceChildren(this.alert('secondary', 'Provjeravam…'));

        try {
            const res = await fetch(this.urlValue, {
                method: 'POST',
                headers: { 'X-CSRF-Token': this.csrfValue, Accept: 'application/json' },
            });
            const data = await res.json();
            if (!res.ok) {
                this.resultsTarget.replaceChildren(this.alert('danger', data.error || ('Greška (HTTP ' + res.status + ').')));
                return;
            }
            this.renderResults(data.results || []);
        } catch (e) {
            this.resultsTarget.replaceChildren(this.alert('danger', 'Mrežna greška: ' + e.message));
        } finally {
            this.buttonTarget.disabled = false;
        }
    }

    renderResults(results) {
        if (!results.length) {
            this.resultsTarget.replaceChildren(this.alert('secondary', 'Nema rezultata.'));
            return;
        }

        const frag = document.createDocumentFragment();
        results.forEach((r) => {
            const cls = r.status === 'ok' ? 'success' : (r.status === 'problem' ? 'danger' : 'secondary');
            const icon = r.status === 'ok' ? '✅' : (r.status === 'problem' ? '❌' : '🔍');

            const wrap = document.createElement('div');
            wrap.className = 'alert alert-' + cls + ' py-2 px-3 mb-2';

            const head = document.createElement('strong');
            head.textContent = icon + ' ' + r.provider;

            const url = document.createElement('div');
            url.className = 'small text-muted';
            url.textContent = r.url;

            const msg = document.createElement('div');
            msg.className = 'small';
            msg.textContent = r.message;

            wrap.append(head, url, msg);
            frag.appendChild(wrap);
        });
        this.resultsTarget.replaceChildren(frag);
    }

    alert(cls, text) {
        const el = document.createElement('div');
        el.className = 'alert alert-' + cls + ' py-2 px-3 mb-0';
        el.textContent = text;
        return el;
    }
}
