import { Controller } from '@hotwired/stimulus';
import { Editor } from '@tiptap/core';
import { buildExtensions, editorToolbarExtensions } from '../tiptap_extensions.js';
import 'prosemirror-view/style/prosemirror.min.css';
import 'prosemirror-gapcursor/style/gapcursor.min.css';
import '../styles/tiptap.css';

/*
 * Mounts Tiptap on the (hidden) content textarea and keeps the textarea in sync, so the
 * field stays form-bound exactly like before. Toolbar actions drive the editor; "insert
 * image" opens the reusable Pass A media library and inserts the selected image as a
 * node.
 *
 * Cross-talk safety: onMediaSelect fires only for `media-library:select` events that
 * bubble to THIS controller's element (the data-action is on the .tiptap wrapper, NOT on
 * document/window). The featured-image picker on the same form has its own wrapper +
 * library instance, so the two never cross.
 */
export default class extends Controller {
    static targets = [
        'editor', 'input', 'library', 'toolbar', 'imageFormat',
        'linkModal', 'linkTabUrl', 'linkTabInternal', 'linkUrlInput', 'linkSearch', 'linkList', 'linkStatus',
    ];
    static values = {
        modules: String,
        linkTargetsUrl: String,
        linkTypePage: String,
        linkTypePost: String,
        linkLoading: String,
        linkNone: String,
        linkLoadError: String,
    };

    connect() {
        this.editor = new Editor({
            element: this.editorTarget,
            extensions: buildExtensions(),
            content: this.inputTarget.value,
            onUpdate: () => this.sync(),
            // The "IMG format" controls only apply to a selected image — keep the trigger
            // enabled/disabled in step with the selection so it's never a confusing no-op.
            onSelectionUpdate: () => this.refreshImageFormat(),
        });
        this.renderExtensionButtons();
        // Sync once so an unedited save still stores the normalised HTML (Trix div->p).
        this.sync();
        this.refreshImageFormat();

        // Close any open toolbar dropdown on an outside click or Escape. Bound once on
        // document (survives Turbo body swaps cleanly via disconnect), never caches nodes.
        this.boundOutsideClick = (e) => {
            if (!e.target.closest('.tiptap__dropdown')) {
                this.closeDropdowns();
            }
        };
        this.boundEscape = (e) => {
            if ('Escape' === e.key) {
                this.closeDropdowns();
            }
        };
        document.addEventListener('click', this.boundOutsideClick);
        document.addEventListener('keydown', this.boundEscape);
    }

    /**
     * Append toolbar buttons contributed by enabled modules (e.g. FormBuilder's "Ubaci
     * formu"). The editor knows nothing about those modules — each provides label +
     * action(editor); gating is by the server's enabled-module list.
     */
    renderExtensionButtons() {
        if (!this.hasToolbarTarget) {
            return;
        }
        const enabled = (this.modulesValue || '').split(/\s+/).filter(Boolean);
        for (const ext of editorToolbarExtensions(enabled)) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'tiptap__btn';
            const name = ext.title || ext.label || '';
            btn.title = name;
            // FA icon when the module supplies one (consistent with the built-in toolbar);
            // text label fallback keeps older module contracts working.
            if (ext.icon) {
                const i = document.createElement('i');
                i.className = ext.icon;
                i.setAttribute('aria-hidden', 'true');
                btn.appendChild(i);
                if (name) {
                    btn.setAttribute('aria-label', name);
                }
            } else {
                btn.textContent = ext.label;
            }
            btn.addEventListener('click', () => ext.action(this.editor));
            this.toolbarTarget.appendChild(btn);
        }
    }

    disconnect() {
        document.removeEventListener('click', this.boundOutsideClick);
        document.removeEventListener('keydown', this.boundEscape);
        if (this.editor) {
            this.editor.destroy();
            this.editor = null;
        }
    }

    sync() {
        this.inputTarget.value = this.editor.getHTML();
    }

    bold() { this.editor.chain().focus().toggleBold().run(); }
    italic() { this.editor.chain().focus().toggleItalic().run(); }
    strike() { this.editor.chain().focus().toggleStrike().run(); }
    // Clear formatting: drop inline marks + reset the selected block(s) to paragraph. Operates
    // only on the selection, so [image]/[form]/columns elsewhere in the doc are untouched.
    clearFormatting() { this.editor.chain().focus().unsetAllMarks().clearNodes().run(); }
    blockquote() { this.editor.chain().focus().toggleBlockquote().run(); }
    code() { this.editor.chain().focus().toggleCode().run(); }
    undo() { this.editor.chain().focus().undo().run(); }
    redo() { this.editor.chain().focus().redo().run(); }

    // --- Toolbar dropdowns (heading / list / align / columns) -----------------------------
    // Each menu item carries plain data-* (data-level / data-list / data-align / data-count)
    // read from event.currentTarget; the command runs, then the menu closes.

    /** Open the dropdown whose trigger was clicked; close any other. Mark the active option. */
    toggleDropdown(event) {
        event.stopPropagation();
        const menu = event.currentTarget.nextElementSibling;
        const wasOpen = menu.classList.contains('is-open');
        this.closeDropdowns();
        if (!wasOpen) {
            menu.classList.add('is-open');
            event.currentTarget.setAttribute('aria-expanded', 'true');
            this.markActive(menu);
        }
    }

    closeDropdowns() {
        this.element.querySelectorAll('.tiptap__menu.is-open').forEach((menu) => {
            menu.classList.remove('is-open');
            menu.previousElementSibling?.setAttribute('aria-expanded', 'false');
        });
    }

    /** Reflect the editor's current block/alignment as an .is-active class on menu items. */
    markActive(menu) {
        menu.querySelectorAll('[data-level]').forEach((el) => {
            const level = Number(el.dataset.level);
            const active = 0 === level ? this.editor.isActive('paragraph') : this.editor.isActive('heading', { level });
            el.classList.toggle('is-active', active);
        });
        menu.querySelectorAll('[data-align]').forEach((el) => {
            el.classList.toggle('is-active', this.editor.isActive({ textAlign: el.dataset.align }));
        });
        menu.querySelectorAll('[data-list]').forEach((el) => {
            const node = 'ordered' === el.dataset.list ? 'orderedList' : 'bulletList';
            el.classList.toggle('is-active', this.editor.isActive(node));
        });
        // Image align/size — active only when an image is selected. The size scale folds
        // width=full in as its top step: when width=full only "Full" is active; otherwise the
        // current size (medium default) is — mirroring the front, where width=full overrides size.
        const img = this.editor.isActive('image') ? this.editor.getAttributes('image') : null;
        menu.querySelectorAll('[data-img-align]').forEach((el) => {
            el.classList.toggle('is-active', null !== img && img.align === el.dataset.imgAlign);
        });
        menu.querySelectorAll('[data-img-size]').forEach((el) => {
            let active = false;
            if (null !== img) {
                active = 'full' === el.dataset.imgSize
                    ? 'full' === img.width
                    : 'full' !== img.width && (img.size || 'medium') === el.dataset.imgSize;
            }
            el.classList.toggle('is-active', active);
        });
    }

    /** Heading dropdown: data-level 0 = Paragraph, 1..4 = H1..H4 (set, not toggle). */
    setHeading(event) {
        const level = Number(event.currentTarget.dataset.level);
        const chain = this.editor.chain().focus();
        (0 === level ? chain.setParagraph() : chain.setHeading({ level })).run();
        this.closeDropdowns();
    }

    /** List dropdown: data-list 'bullet' | 'ordered'. */
    setList(event) {
        const chain = this.editor.chain().focus();
        ('ordered' === event.currentTarget.dataset.list ? chain.toggleOrderedList() : chain.toggleBulletList()).run();
        this.closeDropdowns();
    }

    /** Align dropdown: data-align 'left' | 'center' | 'right' | 'justify' (heading + paragraph). */
    setAlign(event) {
        this.editor.chain().focus().setTextAlign(event.currentTarget.dataset.align).run();
        this.closeDropdowns();
    }

    /** Insert a horizontal rule (StarterKit's HorizontalRule). */
    insertHr() { this.editor.chain().focus().setHorizontalRule().run(); }

    // --- Link picker (modal: URL tab + internal Page/Post tab) ----------------------------

    /** Open the link modal: prefill the URL tab with the current link href, reset to the URL tab. */
    link() {
        if (!this.hasLinkModalTarget) {
            return;
        }
        this.linkUrlInputTarget.value = this.editor.getAttributes('link').href || '';
        this.switchToTab('url');
        this.linkModalTarget.classList.add('is-open');
        this.linkUrlInputTarget.focus();
        this.linkUrlInputTarget.select();
    }

    closeLinkModal() {
        if (this.hasLinkModalTarget) {
            this.linkModalTarget.classList.remove('is-open');
        }
    }

    /** Backdrop click (outside the dialog) closes; clicks inside the dialog do not. */
    onLinkBackdrop(event) {
        if (event.target === this.linkModalTarget) {
            this.closeLinkModal();
        }
    }

    onLinkKeydown(event) {
        if ('Escape' === event.key) {
            this.closeLinkModal();
        }
    }

    onLinkUrlKeydown(event) {
        if ('Enter' === event.key) {
            event.preventDefault();
            this.setLinkUrl();
        }
    }

    /** Tab toggle: data-link-tab 'url' | 'internal'. Lazy-fetch the list when Internal opens. */
    switchTab(event) {
        this.switchToTab(event.currentTarget.dataset.linkTab);
    }

    switchToTab(tab) {
        for (const t of [this.linkTabUrlTarget, this.linkTabInternalTarget]) {
            t.classList.toggle('is-active', t.dataset.linkTab === tab);
        }
        this.element.querySelectorAll('[data-link-panel]').forEach((panel) => {
            panel.hidden = panel.dataset.linkPanel !== tab;
        });
        if ('internal' === tab) {
            this.loadLinkTargets();
            this.linkSearchTarget.focus();
        }
    }

    /** Set the link from the URL tab; empty value removes the link (parity with the old prompt). */
    setLinkUrl() {
        const url = this.linkUrlInputTarget.value.trim();
        this.applyLink(url);
    }

    /**
     * Apply (or, for an empty href, clear) the link over the editor's current selection, then
     * close. chain().focus() restores the selection the editor kept while the modal input had
     * DOM focus (same as the old prompt flow); extendMarkRange edits a whole existing link.
     */
    applyLink(href) {
        const chain = this.editor.chain().focus().extendMarkRange('link');
        ('' === href ? chain.unsetLink() : chain.setLink({ href })).run();
        this.closeLinkModal();
    }

    /** Fetch published Pages/Posts once per open editor; render the list (then client-filter). */
    async loadLinkTargets() {
        if (this.linkTargets) {
            return; // already loaded this session
        }
        this.setLinkStatus(this.linkLoadingValue);
        try {
            const res = await fetch(this.linkTargetsUrlValue, { headers: { Accept: 'application/json' } });
            if (!res.ok) {
                throw new Error('HTTP ' + res.status);
            }
            this.linkTargets = (await res.json()).items || [];
        } catch (e) {
            this.setLinkStatus(this.linkLoadErrorValue);
            return;
        }
        this.renderLinkList(this.linkTargets);
    }

    /** Filter the loaded list by the typed query (case-insensitive title match). */
    filterLinkList() {
        if (!this.linkTargets) {
            return;
        }
        const q = this.linkSearchTarget.value.trim().toLowerCase();
        const filtered = q ? this.linkTargets.filter((i) => i.title.toLowerCase().includes(q)) : this.linkTargets;
        this.renderLinkList(filtered);
    }

    renderLinkList(items) {
        this.linkListTarget.replaceChildren();
        if (0 === items.length) {
            this.setLinkStatus(this.linkNoneValue);
            return;
        }
        this.setLinkStatus('');
        for (const item of items) {
            const li = document.createElement('li');
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'tiptap-linkpicker__item';
            const typeLabel = 'post' === item.type ? this.linkTypePostValue : this.linkTypePageValue;
            // XSS-safe: textContent only, never innerHTML (titles/URLs are user content).
            const title = document.createElement('span');
            title.className = 'tiptap-linkpicker__item-title';
            title.textContent = item.title;
            const badge = document.createElement('span');
            badge.className = 'badge badge-secondary tiptap-linkpicker__item-type';
            badge.textContent = typeLabel;
            const url = document.createElement('span');
            url.className = 'tiptap-linkpicker__item-url text-muted';
            url.textContent = item.url;
            btn.append(title, badge, url);
            btn.addEventListener('click', () => this.applyLink(item.url));
            li.appendChild(btn);
            this.linkListTarget.appendChild(li);
        }
    }

    setLinkStatus(text) {
        if (!this.hasLinkStatusTarget) {
            return;
        }
        this.linkStatusTarget.textContent = text;
        this.linkStatusTarget.hidden = '' === text;
    }

    /** Quick remove-link toolbar button (stays separate from the modal's set/replace flow). */
    unlink() {
        this.editor.chain().focus().unsetLink().run();
    }

    insertImage() {
        this.libraryTarget.dispatchEvent(new CustomEvent('media-library:open'));
    }

    /**
     * Enable the "IMG format" trigger only when an image is selected (its controls are no-ops
     * otherwise). Reactive via onSelectionUpdate. When it becomes disabled, close its menu so an
     * open dropdown can't linger after the selection moves off the image.
     */
    refreshImageFormat() {
        if (!this.hasImageFormatTarget) {
            return;
        }
        const enabled = this.editor.isActive('image');
        this.imageFormatTarget.disabled = !enabled;
        this.imageFormatTarget.setAttribute('aria-disabled', enabled ? 'false' : 'true');
        if (!enabled) {
            this.imageFormatTarget.nextElementSibling?.classList.remove('is-open');
            this.imageFormatTarget.setAttribute('aria-expanded', 'false');
        }
    }

    /**
     * Image alignment (data-img-align 'left'|'center'|'right') and the unified size scale
     * (data-img-size 'thumb'|'medium'|'hero'|'full'). Pure UI: they only set the image node's
     * existing align/size/width attributes — the same data-* the converter already round-trips
     * into [image align/size/width] and the front renders. No-op when no image is selected.
     */
    setImageAlign(event) {
        if (this.editor.isActive('image')) {
            this.editor.chain().focus().updateAttributes('image', { align: event.currentTarget.dataset.imgAlign }).run();
        }
        this.closeDropdowns();
    }

    /**
     * Single size scale: thumb/medium/hero set `size` (and clear `width`); 'full' sets
     * `width=full` (and clears `size`). They're mutually exclusive in the UI — the front already
     * treats width=full as the top of the scale (forcing the hero source, overriding size), so
     * this keeps stored content clean while rendering exactly as before.
     */
    setImageSize(event) {
        if (this.editor.isActive('image')) {
            const value = event.currentTarget.dataset.imgSize;
            const attrs = 'full' === value ? { width: 'full', size: null } : { size: value, width: null };
            this.editor.chain().focus().updateAttributes('image', attrs).run();
        }
        this.closeDropdowns();
    }

    /** Columns dropdown: data-count 2 | 3 | 4. */
    addColumns(event) {
        this.insertColumns(Number(event.currentTarget.dataset.count));
        this.closeDropdowns();
    }

    /**
     * Insert a fixed N-column layout (N empty columns, each seeded with an empty paragraph
     * so `block+` is satisfied). Guarded against nesting: no insert when the cursor is
     * already inside a columns layout (v1 has no nested columns). Count-agnostic node + CSS
     * grid, so 2/3/4 all lay out automatically.
     */
    insertColumns(count) {
        if (this.editor.isActive('columns') || this.editor.isActive('column')) {
            return;
        }
        const columns = Array.from({ length: count }, () => ({
            type: 'column',
            content: [{ type: 'paragraph' }],
        }));
        this.editor.chain().focus().insertContent({
            type: 'columns',
            attrs: { count },
            content: columns,
        }).run();
    }

    onMediaSelect(event) {
        const { id, name, thumbUrl, displayUrl } = event.detail;
        this.editor.chain().focus().insertContent({
            type: 'image',
            // Use the 'medium' display URL (same filter toEditorHtml uses on load) so the
            // fresh insert isn't a small thumb that jumps to medium after reload. The
            // stored shortcode stays [image id=N] (default medium) regardless.
            attrs: { id, src: displayUrl || thumbUrl, alt: name || '' },
        }).run();
    }
}
