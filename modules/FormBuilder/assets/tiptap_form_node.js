import { Node, mergeAttributes } from '@tiptap/core';

/*
 * Tiptap node for an embedded [form id=N]. An atom block (non-editable, selectable,
 * deletable) shown as a labelled card in the editor. The PHP FormShortcodeHtmlConverter
 * turns it into [form id=N] at the storage boundary; here we only preserve data-id (and a
 * display label) across the editor round-trip.
 *
 * Registered into the editor app-level (stimulus_bootstrap) via registerEditorExtension,
 * so the Media editor never references FormBuilder. The node is registered even when the
 * module is toggled off, so existing form embeds round-trip safely (only the insert button
 * is gated).
 */
export const FormEmbed = Node.create({
    name: 'formEmbed',
    group: 'block',
    atom: true,
    selectable: true,
    draggable: true,

    addAttributes() {
        return {
            id: {
                default: null,
                parseHTML: (el) => el.getAttribute('data-id'),
                renderHTML: (attrs) => (attrs.id ? { 'data-id': attrs.id } : {}),
            },
            label: {
                default: null,
                parseHTML: (el) => el.getAttribute('data-label'),
                renderHTML: (attrs) => (attrs.label ? { 'data-label': attrs.label } : {}),
            },
        };
    },

    parseHTML() {
        return [{ tag: 'div[data-tallyst-form]' }];
    },

    renderHTML({ node, HTMLAttributes }) {
        const label = node.attrs.label || ('Forma #' + (node.attrs.id ?? ''));
        return [
            'div',
            mergeAttributes(HTMLAttributes, {
                'data-tallyst-form': '',
                class: 'tiptap-form-embed',
                contenteditable: 'false',
            }),
            '📋 Forma: ' + label,
        ];
    },
});

export default FormEmbed;
