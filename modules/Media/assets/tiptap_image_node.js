import Image from '@tiptap/extension-image';
import { mergeAttributes } from '@tiptap/core';

/*
 * Tiptap image node for Tallyst content. Extends the standard Image node but only
 * recognises OUR marker (<img data-tallyst-image …>) and carries the data the
 * [image id=N size align] shortcode needs. Visual <img> in the editor; the PHP
 * ImageShortcodeHtmlConverter turns it into the shortcode at the storage boundary.
 *
 * Shared by the editor controller AND the Node round-trip test, so the test exercises
 * the exact node definition the editor uses.
 */
export const TallystImage = Image.extend({
    name: 'image',

    addAttributes() {
        return {
            ...this.parent?.(),
            id: {
                default: null,
                parseHTML: (el) => el.getAttribute('data-id'),
                renderHTML: (attrs) => (attrs.id ? { 'data-id': attrs.id } : {}),
            },
            size: {
                default: null,
                parseHTML: (el) => el.getAttribute('data-size'),
                renderHTML: (attrs) => (attrs.size ? { 'data-size': attrs.size } : {}),
            },
            align: {
                default: null,
                parseHTML: (el) => el.getAttribute('data-align'),
                renderHTML: (attrs) => (attrs.align ? { 'data-align': attrs.align } : {}),
            },
            width: {
                default: null,
                parseHTML: (el) => el.getAttribute('data-width'),
                renderHTML: (attrs) => (attrs.width ? { 'data-width': attrs.width } : {}),
            },
        };
    },

    // Only capture our own marked images — a pasted plain <img> is intentionally ignored.
    parseHTML() {
        return [{ tag: 'img[data-tallyst-image]' }];
    },

    renderHTML({ HTMLAttributes }) {
        return ['img', mergeAttributes(HTMLAttributes, { 'data-tallyst-image': '' })];
    },
});

export default TallystImage;
