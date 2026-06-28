import StarterKit from '@tiptap/starter-kit';
import TextAlign from '@tiptap/extension-text-align';
import { TallystImage } from './tiptap_image_node.js';
import { TallystDocument, Columns, Column } from './tiptap_columns_node.js';

/*
 * The Tiptap schema for Tallyst content + an extension point so OTHER modules can plug in
 * their own embed nodes WITHOUT the editor (Media) depending on them. Mirrors the PHP
 * EditorShortcodeConverterInterface IoC on the JS side.
 *
 * Base schema maps to what Trix could author: bold/italic/strike/code, headings, bullet &
 * ordered lists, blockquote, code block, link, hard breaks, history, + the image node and
 * the multi-column layout (columns/column, a PURE HTML node — no shortcode). Both the image
 * and the columns are CORE editor features (Media-owned, no other module) so they are added
 * here DIRECTLY, always present — unlike module embeds, which plug in via the gated path.
 * Anything outside the schema (tables, iframes, inline styles) is dropped by ProseMirror
 * on load — the documented Trix->Tiptap normalisation, proven by the round-trip test.
 *
 * registerEditorExtension({ key, node, toolbar }) is called app-level (stimulus_bootstrap)
 * by modules like FormBuilder. The NODE is always added to the schema (so existing embeds
 * round-trip safely even when the module is toggled off); the TOOLBAR button is gated by
 * the editor against the server's enabled-module list.
 */

const editorExtensions = [];

/**
 * @param {object} ext
 * @param {string} [ext.key]     owning module name (for toolbar gating); omit = always on
 * @param {object} [ext.node]    a Tiptap node added to the schema
 * @param {{label: string, title?: string, icon?: string, action: (editor: object) => void}} [ext.toolbar]
 *   icon = a FontAwesome class string (e.g. 'fa-solid fa-table-list'); when set the button
 *   renders that icon (label/title become the accessible name), else it falls back to a text label.
 */
export function registerEditorExtension(ext) {
    editorExtensions.push(ext);
}

export function buildExtensions() {
    const nodes = editorExtensions.map((e) => e.node).filter(Boolean);
    return [
        StarterKit.configure({
            // Replace StarterKit's Document with ours so the top level also accepts the
            // columns layout node (TallystDocument content = '(block | columns)+').
            document: false,
            heading: { levels: [1, 2, 3, 4] },
            // Keep content stable on load: don't auto-link typed URLs, don't navigate on
            // click, don't force target=_blank/rel onto existing links.
            link: { openOnClick: false, autolink: false, HTMLAttributes: { target: null, rel: null } },
        }),
        // Text alignment on paragraphs + headings. Stored as an inline `text-align` style on
        // the block (the front renders it natively; the schema now PRESERVES that one style —
        // other inline styles are still dropped). Default 'left' renders no style (clean content).
        TextAlign.configure({ types: ['heading', 'paragraph'] }),
        TallystDocument,
        TallystImage,
        Columns,
        Column,
        ...nodes,
    ];
}

/**
 * Toolbar buttons contributed by modules, filtered to those whose module is enabled.
 * @param {string[]} enabledKeys
 */
export function editorToolbarExtensions(enabledKeys) {
    return editorExtensions
        .filter((e) => e.toolbar && (!e.key || enabledKeys.includes(e.key)))
        .map((e) => e.toolbar);
}
