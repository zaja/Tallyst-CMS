/*
 * Unsaved-changes guard for admin edit/new forms. Admin-only (imported by assets/admin.js).
 *
 * Turbo is active in the admin (shared Stimulus app), so in-app link clicks are Turbo visits —
 * `beforeunload` alone misses them. We cover BOTH: `beforeunload` (tab close / reload / external) and
 * `turbo:before-visit` (in-app navigation).
 *
 * Dirty detection = serialize-and-COMPARE against a snapshot taken AFTER controllers settle, NOT raw
 * input events — because our Stimulus controllers (Tiptap writes getHTML() on connect, form-builder /
 * rules mutate inputs on connect) would otherwise mark a freshly-loaded form dirty.
 *
 * Guarded forms: any `form[data-dirty-guard]` (custom admin forms tag themselves) plus, on EA crud
 * edit/new pages (body.ea-edit / body.ea-new), the page's POST form(s) — so Page/Post/User/Theme/etc.
 * are covered automatically. Saving (submit / turbo:submit-start) suppresses the warning.
 */
(function () {
    if (window.__tallystDirtyGuard) {
        return;
    }
    window.__tallystDirtyGuard = true;

    // Translated by the admin layout (window.__tallystI18n); English fallback.
    const dirtyMsg = (window.__tallystI18n || {}).dirtyGuard || 'You have unsaved changes. Leave the page without saving?';

    const snapshots = new WeakMap();
    let saving = false;

    function guardedForms() {
        const forms = new Set();
        document.querySelectorAll('form[data-dirty-guard]').forEach((f) => forms.add(f));
        if (document.body && (document.body.classList.contains('ea-edit') || document.body.classList.contains('ea-new'))) {
            document.querySelectorAll('form').forEach((f) => {
                const method = (f.getAttribute('method') || 'get').toLowerCase();
                if ('post' === method && !f.hasAttribute('data-no-dirty-guard')) {
                    forms.add(f);
                }
            });
        }
        return [...forms];
    }

    // Serialize the form's fields, EXCLUDING file inputs. A file input is never meaningful
    // "unsaved content" — uploads go via AJAX, not a form submit — and, crucially, FilePond
    // (media pickers) ALWAYS names its file input (its `name` option defaults to 'filepond')
    // and builds it ASYNCHRONOUSLY via its own requestAnimationFrame loop, so the input lands
    // AFTER our snapshot. Including it would make the serialization change post-snapshot and
    // falsely read as dirty (bit the Branding tab with 4 pickers). Filtering File/Blob values
    // (rather than the raw new URLSearchParams(FormData)) drops every file input generically.
    function serialize(form) {
        try {
            const params = new URLSearchParams();
            for (const [key, value] of new FormData(form).entries()) {
                if (value instanceof File || value instanceof Blob) {
                    continue;
                }
                params.append(key, value);
            }
            return params.toString();
        } catch {
            return null;
        }
    }

    function snapshotAll() {
        guardedForms().forEach((form) => snapshots.set(form, serialize(form)));
    }

    function isDirty() {
        return guardedForms().some((form) => {
            const snap = snapshots.get(form);
            return undefined !== snap && null !== snap && serialize(form) !== snap;
        });
    }

    // Snapshot after the DOM + Stimulus controllers settle (double rAF) on initial load and each Turbo visit.
    function scheduleSnapshot() {
        saving = false;
        requestAnimationFrame(() => requestAnimationFrame(snapshotAll));
    }

    document.addEventListener('DOMContentLoaded', scheduleSnapshot);
    document.addEventListener('turbo:load', scheduleSnapshot);

    // Saving navigates away legitimately — don't warn.
    document.addEventListener('submit', () => { saving = true; }, true);
    document.addEventListener('turbo:submit-start', () => { saving = true; });

    // Full navigation / tab close / reload.
    window.addEventListener('beforeunload', (event) => {
        if (!saving && isDirty()) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    // In-app (Turbo Drive) navigation — links within the admin.
    document.addEventListener('turbo:before-visit', (event) => {
        if (!saving && isDirty() && !window.confirm(dirtyMsg)) {
            event.preventDefault();
        }
    });
})();
