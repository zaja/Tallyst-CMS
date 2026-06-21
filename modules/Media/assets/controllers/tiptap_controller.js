import { Controller } from '@hotwired/stimulus';
import { Editor } from '@tiptap/core';
import { buildExtensions } from '../tiptap_extensions.js';
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
    static targets = ['editor', 'input', 'library'];

    connect() {
        this.editor = new Editor({
            element: this.editorTarget,
            extensions: buildExtensions(),
            content: this.inputTarget.value,
            onUpdate: () => this.sync(),
        });
        // Sync once so an unedited save still stores the normalised HTML (Trix div->p).
        this.sync();
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

    onMediaSelect(event) {
        const { id, name, thumbUrl } = event.detail;
        this.editor.chain().focus().insertContent({
            type: 'image',
            attrs: { id, src: thumbUrl, alt: name || '' },
        }).run();
    }
}
