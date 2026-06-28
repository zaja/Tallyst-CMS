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
    static targets = ['editor', 'input', 'library', 'toolbar'];
    static values = { modules: String, linkPrompt: String };

    connect() {
        this.editor = new Editor({
            element: this.editorTarget,
            extensions: buildExtensions(),
            content: this.inputTarget.value,
            onUpdate: () => this.sync(),
        });
        this.renderExtensionButtons();
        // Sync once so an unedited save still stores the normalised HTML (Trix div->p).
        this.sync();

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
            btn.title = ext.title || ext.label;
            btn.textContent = ext.label;
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

    link() {
        const previous = this.editor.getAttributes('link').href || '';
        const url = window.prompt(this.linkPromptValue, previous);
        if (url === null) {
            return; // cancelled
        }
        if (url === '') {
            this.editor.chain().focus().extendMarkRange('link').unsetLink().run();
            return;
        }
        this.editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
    }

    unlink() {
        this.editor.chain().focus().unsetLink().run();
    }

    insertImage() {
        this.libraryTarget.dispatchEvent(new CustomEvent('media-library:open'));
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
