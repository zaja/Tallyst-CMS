import { Node, mergeAttributes } from '@tiptap/core';

/*
 * Inline icon node for Tallyst content — the WYSIWYG side of the [icon name=X] shortcode.
 * A CORE editor feature (like the image/columns nodes) so it's added DIRECTLY in
 * buildExtensions, always present. The PHP IconEditorConverter turns it into [icon name=X]
 * at the storage boundary; here we only preserve `name` across the round-trip.
 *
 * INLINE atom (inline:true, group:'inline', atom:true) — an icon sits mid-sentence, so it must
 * live INSIDE a paragraph like a mention/emoji, NOT break out into its own block. inline:true is
 * MANDATORY: without it ProseMirror treats the node as block and the icon jumps out of the line
 * (the main inline round-trip risk — asserted by the Node test).
 *
 * WYSIWYG split (deterministic serialization vs display):
 *  - renderHTML → a CLEAN marker `<span data-tallyst-icon data-name=X>` (NO svg). This is what
 *    getHTML() serializes and what the converter/round-trip test see — projection-independent.
 *  - addNodeView → the REAL <svg> for the editor display only, looked up in the iconSet
 *    projection (fed from PHP IconRegistry via icon_set_json). Never serialized. An unknown name
 *    renders nothing but the `name` attribute is preserved, so it round-trips gracefully.
 *
 * The iconSet is passed via .configure({ iconSet }) in buildExtensions (the controller reads it
 * from the icon-set data-value). In the Node round-trip test buildExtensions() runs with no set →
 * the NodeView degrades to empty, but renderHTML (the marker) is unaffected, so the test is clean.
 *
 * Shared by the editor controller AND the Node round-trip test, so the test exercises the exact
 * node definition the editor uses.
 */
export const TallystIcon = Node.create({
    name: 'tallystIcon',
    inline: true,
    group: 'inline',
    atom: true,
    selectable: true,
    draggable: false,

    addOptions() {
        return { iconSet: {} };
    },

    addAttributes() {
        return {
            name: {
                default: null,
                parseHTML: (el) => el.getAttribute('data-name'),
                renderHTML: (attrs) => (attrs.name ? { 'data-name': attrs.name } : {}),
            },
        };
    },

    parseHTML() {
        return [{ tag: 'span[data-tallyst-icon]' }];
    },

    // Deterministic marker for getHTML/serialization — name only, never the SVG.
    renderHTML({ HTMLAttributes }) {
        return ['span', mergeAttributes(HTMLAttributes, { 'data-tallyst-icon': '' })];
    },

    // Real inline SVG for the editor display only (from the PHP-fed projection). Decorative
    // (aria-hidden). The viewBox/body come from the trusted registry projection — safe to inline.
    addNodeView() {
        return ({ node }) => {
            const dom = document.createElement('span');
            dom.setAttribute('data-tallyst-icon', '');
            dom.setAttribute('data-name', node.attrs.name || '');
            dom.className = 'tiptap-icon';
            dom.setAttribute('contenteditable', 'false');
            const icon = this.options.iconSet[node.attrs.name];
            if (icon) {
                dom.innerHTML = `<svg class="tallyst-icon" viewBox="${icon.viewBox}" width="1em" height="1em" fill="currentColor" aria-hidden="true">${icon.body}</svg>`;
            }
            return { dom, ignoreMutation: () => true };
        };
    },
});

export default TallystIcon;
