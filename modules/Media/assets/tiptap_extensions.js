import StarterKit from '@tiptap/starter-kit';
import { TallystImage } from './tiptap_image_node.js';

/*
 * The ONE Tiptap schema for Tallyst content. Imported by both the editor controller and
 * the Node round-trip test, so the test validates the exact node/mark set the editor
 * uses (no drift). StarterKit (v3) already bundles Link, so it's configured here rather
 * than added separately.
 *
 * Coverage maps to what Trix could author: bold/italic/strike/code, headings, bullet &
 * ordered lists, blockquote, code block, link, hard breaks, history. Anything outside
 * this schema (tables, iframes, inline styles, …) is dropped by ProseMirror on load —
 * that is the documented Trix->Tiptap normalisation, proven by the round-trip test.
 */
export function buildExtensions() {
    return [
        StarterKit.configure({
            heading: { levels: [1, 2, 3] },
            // Keep content stable on load: don't auto-link typed URLs, don't navigate on
            // click, and don't force target=_blank/rel onto existing links (preserve plain
            // <a href> as Trix authored it).
            link: { openOnClick: false, autolink: false, HTMLAttributes: { target: null, rel: null } },
        }),
        TallystImage,
    ];
}
