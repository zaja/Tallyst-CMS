import { Controller } from '@hotwired/stimulus';

/*
 * Featured-image picker widget. Wraps a hidden field (the Media id, mapped to the FK by
 * MediaPickerType) plus a thumbnail preview and a button that opens the reusable
 * media-library modal. It does NOT know how the library works — it opens it by
 * dispatching `media-library:open` and updates itself from the `media-library:select`
 * event the library emits. Same FK/mapping as before; only the widget changed.
 */
export default class extends Controller {
    static targets = ['input', 'previewImg', 'placeholder', 'clear', 'library'];

    open() {
        this.libraryTarget.dispatchEvent(new CustomEvent('media-library:open'));
    }

    onSelect(event) {
        const { id, thumbUrl } = event.detail;
        this.inputTarget.value = id || '';
        this.applyPreview(thumbUrl);
    }

    clear() {
        this.inputTarget.value = '';
        this.applyPreview('');
    }

    applyPreview(thumbUrl) {
        const hasImage = Boolean(this.inputTarget.value);
        if (this.hasPreviewImgTarget) {
            this.previewImgTarget.src = thumbUrl || '';
            this.previewImgTarget.hidden = !hasImage;
        }
        if (this.hasPlaceholderTarget) {
            this.placeholderTarget.hidden = hasImage;
        }
        if (this.hasClearTarget) {
            this.clearTarget.hidden = !hasImage;
        }
    }
}
