import './styles/form_picker.css';

/*
 * Form picker for the editor's "Ubaci formu" button. Entirely client-built (no server
 * template, no DOM mount point), so FormBuilder contributes it app-level without the Media
 * editor including anything. Fetches the forms list, and on selection inserts a formEmbed
 * node into the editor it was handed.
 */

const LIST_URL = '/admin/forms-list';

// Translations set by the admin layout (bootstrap-wired modal, no per-page element). English fallbacks.
const i18n = () => (window.__tallystI18n || {}).formPicker || {};

async function fetchForms() {
    const res = await fetch(LIST_URL, { headers: { Accept: 'application/json' } });
    if (!res.ok) {
        throw new Error('HTTP ' + res.status);
    }
    return (await res.json()).items || [];
}

function buildModal() {
    const t = i18n();
    const backdrop = document.createElement('div');
    backdrop.className = 'fb-form-picker__backdrop';
    backdrop.innerHTML = `
        <div class="fb-form-picker__dialog" role="dialog" aria-modal="true">
            <div class="fb-form-picker__header">
                <strong>${t.title || 'Select a form'}</strong>
                <button type="button" class="btn-close" aria-label="${t.close || 'Close'}"></button>
            </div>
            <p class="fb-form-picker__status text-muted">${t.loading || 'Loading…'}</p>
            <ul class="fb-form-picker__list"></ul>
        </div>`;
    return backdrop;
}

/**
 * Open the picker and insert the chosen form as a node into `editor`.
 * @param {object} editor a Tiptap editor instance
 */
export async function openFormPicker(editor) {
    const backdrop = buildModal();
    const dialog = backdrop.querySelector('.fb-form-picker__dialog');
    const status = backdrop.querySelector('.fb-form-picker__status');
    const list = backdrop.querySelector('.fb-form-picker__list');

    const close = () => {
        backdrop.remove();
        document.body.style.overflow = '';
    };
    backdrop.addEventListener('click', (e) => { if (e.target === backdrop) close(); });
    backdrop.querySelector('.btn-close').addEventListener('click', close);

    document.body.appendChild(backdrop);
    document.body.style.overflow = 'hidden';

    const t = i18n();
    let forms;
    try {
        forms = await fetchForms();
    } catch (e) {
        status.textContent = t.load_error || "Couldn't load forms.";
        return;
    }

    if (forms.length === 0) {
        status.textContent = t.none || 'No forms yet. Create one first.';
        return;
    }
    status.hidden = true;

    for (const form of forms) {
        const li = document.createElement('li');
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'fb-form-picker__item';
        btn.innerHTML = `<span>${form.name}</span>`
            + (form.published ? '' : ` <span class="badge badge-secondary">${t.draft || 'draft'}</span>`);
        btn.addEventListener('click', () => {
            editor.chain().focus().insertContent({
                type: 'formEmbed',
                attrs: { id: String(form.id), label: form.name },
            }).run();
            close();
        });
        li.appendChild(btn);
        list.appendChild(li);
    }
    void dialog;
}
