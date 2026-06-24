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
    static values = { modules: String };

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
    paragraph() { this.editor.chain().focus().setParagraph().run(); }
    // Clear formatting: drop inline marks + reset the selected block(s) to paragraph. Operates
    // only on the selection, so [image]/[form]/columns elsewhere in the doc are untouched.
    clearFormatting() { this.editor.chain().focus().unsetAllMarks().clearNodes().run(); }
    h2() { this.editor.chain().focus().toggleHeading({ level: 2 }).run(); }
    h3() { this.editor.chain().focus().toggleHeading({ level: 3 }).run(); }
    blockquote() { this.editor.chain().focus().toggleBlockquote().run(); }
    code() { this.editor.chain().focus().toggleCode().run(); }
    bulletList() { this.editor.chain().focus().toggleBulletList().run(); }
    orderedList() { this.editor.chain().focus().toggleOrderedList().run(); }
    undo() { this.editor.chain().focus().undo().run(); }
    redo() { this.editor.chain().focus().redo().run(); }

    link() {
        const previous = this.editor.getAttributes('link').href || '';
        const url = window.prompt('URL poveznice:', previous);
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
     * Toggle the SELECTED image between normal and full-container width (per-image). No-op when
     * no image is selected. Feedback is immediate via the editor preview (data-width="full" CSS);
     * the PHP converter stores it as [image … width=full].
     */
    toggleImageWidth() {
        if (!this.editor.isActive('image')) {
            return;
        }
        const current = this.editor.getAttributes('image').width;
        this.editor.chain().focus().updateAttributes('image', { width: 'full' === current ? null : 'full' }).run();
    }

    columns2() { this.insertColumns(2); }
    columns3() { this.insertColumns(3); }

    /**
     * Insert a fixed N-column layout (N empty columns, each seeded with an empty paragraph
     * so `block+` is satisfied). Guarded against nesting: no insert when the cursor is
     * already inside a columns layout (v1 has no nested columns).
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
