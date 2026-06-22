import { Controller } from '@hotwired/stimulus';
import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import 'prosemirror-view/style/prosemirror.min.css';
import 'prosemirror-gapcursor/style/gapcursor.min.css';
import '../styles/email_editor.css';

/*
 * Tiptap-LITE editor for the email template body (Pass 2). Deliberately separate from the
 * page-content editor (media--tiptap): email has no shortcode concept, so this is raw HTML
 * in/out with NO EditorContentConverter and NONE of the page-content nodes (columns, [form],
 * media images). Just StarterKit (bold/italic/link/lists/headings/paragraph), so the body
 * round-trips as a plain HTML string in the same email_template.body column.
 *
 * "Insert tag" drops the literal {tag} text at the cursor — a bare placeholder the Pass-1
 * renderer replaces later, NOT a custom node. So the engine + reset guard stay untouched
 * (Tiptap leaves {…} literal — they aren't HTML-special — so {reset_url} survives getHTML()).
 */
export default class extends Controller {
    static targets = ['editor', 'input'];

    connect() {
        this.editor = new Editor({
            element: this.editorTarget,
            extensions: [StarterKit.configure({ heading: { levels: [2, 3] } })],
            content: this.inputTarget.value,
            onUpdate: () => this.sync(),
        });
        // Sync once so an unedited save still stores the editor-normalised HTML.
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
    paragraph() { this.editor.chain().focus().setParagraph().run(); }
    h2() { this.editor.chain().focus().toggleHeading({ level: 2 }).run(); }
    h3() { this.editor.chain().focus().toggleHeading({ level: 3 }).run(); }
    bulletList() { this.editor.chain().focus().toggleBulletList().run(); }
    orderedList() { this.editor.chain().focus().toggleOrderedList().run(); }
    // Clear formatting: drop inline marks + reset the selected block(s) to paragraph.
    clearFormatting() { this.editor.chain().focus().unsetAllMarks().clearNodes().run(); }

    /** Add / edit / remove a link: pre-fills the current href; empty URL removes it. */
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

    /** Insert the literal {tag} text at the cursor (works even when the editor is empty). */
    insertTag(event) {
        const tag = event.params.tag;
        if (tag) {
            this.editor.chain().focus().insertContent(tag).run();
        }
    }
}
