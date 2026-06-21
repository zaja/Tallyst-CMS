import StarterKit from '@tiptap/starter-kit';
import { TallystImage } from './tiptap_image_node.js';

/*
 * The Tiptap schema for Tallyst content + an extension point so OTHER modules can plug in
 * their own embed nodes WITHOUT the editor (Media) depending on them. Mirrors the PHP
 * EditorShortcodeConverterInterface IoC on the JS side.
 *
 * Base schema maps to what Trix could author: bold/italic/strike/code, headings, bullet &
 * ordered lists, blockquote, code block, link, hard breaks, history, + the image node.
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
 * @param {{label: string, title?: string, action: (editor: object) => void}} [ext.toolbar]
 */
export function registerEditorExtension(ext) {
    editorExtensions.push(ext);
}

export function buildExtensions() {
    const nodes = editorExtensions.map((e) => e.node).filter(Boolean);
    return [
        StarterKit.configure({
            heading: { levels: [1, 2, 3] },
            // Keep content stable on load: don't auto-link typed URLs, don't navigate on
            // click, don't force target=_blank/rel onto existing links.
            link: { openOnClick: false, autolink: false, HTMLAttributes: { target: null, rel: null } },
        }),
        TallystImage,
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
