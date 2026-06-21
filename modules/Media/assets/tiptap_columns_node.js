import { Node, mergeAttributes } from '@tiptap/core';
import Document from '@tiptap/extension-document';

/*
 * Multi-column layout for Tallyst content (Prolaz C). A FIXED 2-or-3 equal-column layout
 * (v1: not resizable, no per-column widths, no nesting). Unlike the image/form embeds,
 * columns are a PURE HTML node — there is NO shortcode and NO EditorShortcodeConverter for
 * them: Tiptap's parseHTML/renderHTML carry them straight in and out of stored content as
 *   <div class="tallyst-columns" data-columns="N">
 *     <div class="tallyst-column">…blocks…</div> …
 *   </div>
 * The PHP converters touch only their own disjoint patterns, so columns pass through
 * untouched (and [image]/[form] embeds nested INSIDE a column still convert, since the
 * converters run over the whole HTML).
 *
 * These nodes are core editor features (they live in Media, the mandatory editor module,
 * and don't depend on any other module), so they are added to the schema DIRECTLY in
 * buildExtensions — like the image node, always present — NOT via the module-gated
 * registerEditorExtension path (that one is for FormBuilder's form button).
 *
 * Shared by the editor controller AND the Node round-trip test, so the test exercises the
 * exact node definitions the editor uses.
 */

/*
 * A single column: holds any block content (paragraphs, images, forms, lists, …) so module
 * embeds work inside it. `column` is NOT in the `block` group and the document only accepts
 * `(block | columns)+`, so a `columns` can never sit inside a `column` — nested columns are
 * structurally forbidden (ProseMirror lifts a malformed inner `columns` back out). `block+`
 * means a column always carries at least one block; an emptied column keeps an empty
 * paragraph. `isolating` keeps editing/selection from leaking across column boundaries.
 */
export const Column = Node.create({
    name: 'column',
    content: 'block+',
    isolating: true,

    parseHTML() {
        return [{ tag: 'div.tallyst-column' }];
    },

    renderHTML({ HTMLAttributes }) {
        return ['div', mergeAttributes(HTMLAttributes, { class: 'tallyst-column' }), 0];
    },
});

/*
 * The columns container: holds `column+`. `count` (2|3) is stored as data-columns on the
 * wrapper — informational/CSS hook; the layout itself is count-agnostic (the theme grid
 * uses grid-auto-flow:column / grid-auto-columns:1fr, so it lays out however many columns
 * are present). In its own `columns` group (not `block`) so it is top-level only.
 */
export const Columns = Node.create({
    name: 'columns',
    group: 'columns',
    content: 'column+',
    isolating: true,
    defining: true,

    addAttributes() {
        return {
            count: {
                default: 2,
                parseHTML: (el) => Number(el.getAttribute('data-columns')) || 2,
                renderHTML: (attrs) => ({ 'data-columns': attrs.count || 2 }),
            },
        };
    },

    parseHTML() {
        return [{ tag: 'div.tallyst-columns' }];
    },

    renderHTML({ HTMLAttributes }) {
        return ['div', mergeAttributes(HTMLAttributes, { class: 'tallyst-columns' }), 0];
    },
});

/*
 * Document override so the top level accepts columns alongside normal blocks. Disabling
 * StarterKit's bundled Document and adding this is what keeps `columns` out of the `block`
 * group (and thus out of a `column`), while still letting it appear at the page top level.
 */
export const TallystDocument = Document.extend({
    content: '(block | columns)+',
});

export default Columns;
