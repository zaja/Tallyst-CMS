import { Node, mergeAttributes } from '@tiptap/core';

/*
 * Vertical spacer — a curated ATOM block that inserts blank vertical space between blocks (the
 * content is otherwise flush). Same shape as StarterKit's HorizontalRule: a leaf block node (no
 * editable content), inserted from the toolbar. Serialised as an EMPTY
 *   <div class="tallyst-spacer tallyst-spacer--{size}"></div>
 * with a curated size allowlist — the actual heights live in the theme CSS (.tallyst-spacer--*),
 * NOT a free pixel input. Same injection-safe schema-allowlist pattern as the columns/display/
 * button styles. Added directly in buildExtensions (a core editor feature, like image/columns).
 */
export const SPACER_SIZES = ['sm', 'md', 'lg'];

export const TallystSpacer = Node.create({
    name: 'tallystSpacer',
    group: 'block',
    atom: true,
    selectable: true,

    addAttributes() {
        return {
            // size = one of SPACER_SIZES; unknown → 'md' (the size is cosmetic, so we default it
            // rather than drop the whole node). Serialised as the fixed .tallyst-spacer--{size} class.
            size: {
                default: 'md',
                parseHTML: (el) => {
                    const m = el.className.match(new RegExp(`\\btallyst-spacer--(${SPACER_SIZES.join('|')})\\b`));
                    return m ? m[1] : 'md';
                },
                renderHTML: (attrs) => {
                    const size = SPACER_SIZES.includes(attrs.size) ? attrs.size : 'md';

                    return { class: `tallyst-spacer tallyst-spacer--${size}` };
                },
            },
        };
    },

    parseHTML() {
        // Class-specific so it never matches the columns/column divs.
        return [{ tag: 'div.tallyst-spacer' }];
    },

    renderHTML({ HTMLAttributes }) {
        // Leaf: no content hole (0). mergeAttributes carries the size class from renderHTML above.
        return ['div', mergeAttributes(HTMLAttributes)];
    },
});

export default TallystSpacer;
