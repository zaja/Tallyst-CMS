import { Mark, mergeAttributes } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import TextAlign from '@tiptap/extension-text-align';
import Heading from '@tiptap/extension-heading';
import Link from '@tiptap/extension-link';
import { TallystImage } from './tiptap_image_node.js';
import { TallystIcon } from './tiptap_icon_node.js';
import { TallystDocument, Columns, Column } from './tiptap_columns_node.js';
import { TallystSpacer } from './tiptap_spacer_node.js';

/*
 * Display headings (landing typography): the StarterKit Heading extended with ONE optional
 * `display` attribute, serialised ONLY as a fixed class `display-1` / `display-2`. It stays a
 * real <h1> (semantic + SEO clean, important for hide-title landing pages) — display is just a
 * visual treatment chosen in the heading dropdown. Same fixed-allowlist pattern as the image
 * node's size/align/width and columns.count: parseHTML reads ONLY display-1/display-2 -> 1/2,
 * renderHTML emits ONLY `class="display-N"` when set (nothing otherwise) — so an arbitrary class
 * can never be authored or persisted (ProseMirror drops anything the schema doesn't capture).
 * The VISUAL scale lives in the theme CSS (.display-1/.display-2) — currently a TEMPORARY
 * placeholder; the real scale/weight/spacing is designed with Tema v2.
 */
const TallystHeading = Heading.extend({
    addAttributes() {
        return {
            ...this.parent?.(), // keep `level`
            display: {
                default: null,
                parseHTML: (el) => {
                    const m = el.className.match(/\bdisplay-([12])\b/);
                    return m ? Number(m[1]) : null;
                },
                renderHTML: (attrs) => (attrs.display ? { class: `display-${attrs.display}` } : {}),
            },
        };
    },
});

/*
 * Curated content buttons (landing CTAs). A button IS a styled LINK — the StarterKit Link mark
 * extended with ONE optional `buttonStyle` attribute, serialised ONLY as a fixed class
 * `tallyst-btn tallyst-btn--{style}` on the <a>. Same fixed-allowlist pattern as the display
 * heading + the image size/align: parseHTML reads ONLY tallyst-btn--{primary|secondary|ghost} ->
 * the style, renderHTML emits ONLY the known class (else a clean <a>) — an arbitrary class can never
 * be authored or persisted. Label = the linked text (inline editing); the full link picker (URL /
 * internal Page-Post / new-tab) is reused for the destination. Free colour/size is intentionally
 * NOT offered (consistency > power). The VISUAL (`.tallyst-btn`) is a Tema-v2 placeholder for now.
 */
export const BUTTON_STYLES = ['primary', 'secondary', 'ghost'];

const TallystLink = Link.extend({
    addAttributes() {
        return {
            ...this.parent?.(), // keep href/target/rel
            // Neutralise the base <a> class so ONLY buttonStyle emits one — otherwise the mark
            // preserves any authored class verbatim (an injection hole; proven: tallyst-btn--evil
            // survived without this). parseHTML drops the raw class; renderHTML adds nothing here.
            class: {
                default: null,
                parseHTML: () => null,
                renderHTML: () => ({}),
            },
            buttonStyle: {
                default: null,
                parseHTML: (el) => {
                    const m = (el.getAttribute('class') || '').match(/\btallyst-btn--([a-z]+)\b/);
                    return m && BUTTON_STYLES.includes(m[1]) ? m[1] : null;
                },
                renderHTML: (attrs) => (attrs.buttonStyle && BUTTON_STYLES.includes(attrs.buttonStyle)
                    ? { class: `tallyst-btn tallyst-btn--${attrs.buttonStyle}` }
                    : {}),
            },
        };
    },
});

/*
 * Curated text colour — a standalone mark that colours a text selection from a CURATED palette
 * (NOT a free colour picker; consistency > power). Serialised ONLY as a fixed class
 * `tallyst-color--{name}` on a <span>, with the same injection-safe allowlist pattern as every
 * other curated style (parseHTML reads ONLY an allowlisted name, renderHTML emits ONLY the known
 * class). A CLASS, not an inline `style="color:…"` — the theme owns the actual values (change the
 * brand orange and all coloured content follows), and it fits the schema's "drop every inline
 * style except text-align" rule (no @tiptap/extension-color / -text-style dependency needed).
 */
export const TEXT_COLORS = ['brand', 'brand-strong', 'ink', 'ink-2', 'blue', 'green', 'muted', 'muted-light'];

const TallystColor = Mark.create({
    name: 'textColor',

    addAttributes() {
        return {
            color: {
                default: null,
                parseHTML: (el) => {
                    const m = (el.getAttribute('class') || '').match(/\btallyst-color--([a-z0-9-]+)\b/);
                    return m && TEXT_COLORS.includes(m[1]) ? m[1] : null;
                },
                renderHTML: (attrs) => (attrs.color && TEXT_COLORS.includes(attrs.color)
                    ? { class: `tallyst-color--${attrs.color}` }
                    : {}),
            },
        };
    },

    parseHTML() {
        // Only a <span> that already carries a tallyst-color-- class (an arbitrary span is ignored).
        return [{
            tag: 'span',
            getAttrs: (el) => (/\btallyst-color--/.test(el.getAttribute('class') || '') ? {} : false),
        }];
    },

    renderHTML({ HTMLAttributes }) {
        return ['span', mergeAttributes(HTMLAttributes), 0];
    },

    addCommands() {
        return {
            setTextColor: (color) => ({ commands }) => (TEXT_COLORS.includes(color)
                ? commands.setMark('textColor', { color })
                : false),
            unsetTextColor: () => ({ commands }) => commands.unsetMark('textColor'),
        };
    },
});

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

/**
 * @param {object} [iconSet] name -> {viewBox, body} projection of the Core IconRegistry UI group
 *   (from icon_set_json), used by the tallystIcon NodeView + picker. Empty in the Node test.
 */
export function buildExtensions(iconSet = {}) {
    const nodes = editorExtensions.map((e) => e.node).filter(Boolean);
    return [
        StarterKit.configure({
            // Replace StarterKit's Document with ours so the top level also accepts the
            // columns layout node (TallystDocument content = '(block | columns)+').
            document: false,
            // Replace StarterKit's Heading with TallystHeading (adds the optional display attr);
            // levels are configured on it below.
            heading: false,
            // Replace StarterKit's Link with TallystLink (adds the optional buttonStyle attr);
            // link options are configured on it below.
            link: false,
        }),
        // Text alignment on paragraphs + headings. Stored as an inline `text-align` style on
        // the block (the front renders it natively; the schema now PRESERVES that one style —
        // other inline styles are still dropped). Default 'left' renders no style (clean content).
        TextAlign.configure({ types: ['heading', 'paragraph'] }),
        // Our heading keeps the StarterKit name ('heading'), so TextAlign's types + isActive
        // checks are unchanged; just carries the extra display attribute.
        // levels 1-6 so h5/h6 ROUND-TRIP (the theme styles h6 as the brand "eyebrow"; without 6 in
        // the schema ProseMirror would drop an authored/seeded <h6> to a paragraph on edit+save).
        // The dropdown authors h1-h4 + the Eyebrow (h6) item; display is still level-1 only.
        TallystHeading.configure({ levels: [1, 2, 3, 4, 5, 6] }),
        // Our link keeps the name 'link' (so the link picker + isActive are unchanged) — same
        // stable-on-load config as before, plus the buttonStyle attribute for CTA buttons.
        TallystLink.configure({ openOnClick: false, autolink: false, HTMLAttributes: { target: null, rel: null } }),
        TallystColor,
        TallystDocument,
        TallystImage,
        // Inline icon node (core content, like image/columns). iconSet feeds the display NodeView.
        TallystIcon.configure({ iconSet }),
        Columns,
        Column,
        TallystSpacer,
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
