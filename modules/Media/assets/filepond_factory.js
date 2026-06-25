import * as FilePond from 'filepond';
import FilePondPluginImagePreview from 'filepond-plugin-image-preview';
import FilePondPluginFileValidateType from 'filepond-plugin-file-validate-type';
import FilePondPluginFileValidateSize from 'filepond-plugin-file-validate-size';
import 'filepond/dist/filepond.min.css';
import 'filepond-plugin-image-preview/dist/filepond-plugin-image-preview.min.css';

/*
 * Shared FilePond setup for the Media module — used by BOTH the library modal upload
 * zone and the bulk-upload page, so the client-side rules stay in one place. Client
 * validation MIRRORS the server (Assert\Image: raster only, ≤5 MB); the server remains
 * the authority (MediaUploader re-validates the same Assert\Image).
 */

// Mirror of the entity's Assert\Image mimeTypes + maxSize.
const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
const MAX_SIZE = '5MB';

let pluginsRegistered = false;

function registerPluginsOnce() {
    if (pluginsRegistered) {
        return;
    }
    FilePond.registerPlugin(
        FilePondPluginImagePreview,
        FilePondPluginFileValidateType,
        FilePondPluginFileValidateSize,
    );
    pluginsRegistered = true;
}

/**
 * Create a FilePond instance bound to `input`.
 *
 * @param {HTMLInputElement} input     a file input element
 * @param {object}           opts
 * @param {string}           opts.uploadUrl   POST target (media_upload)
 * @param {string}           opts.csrfToken   token sent as X-CSRF-Token
 * @param {(id: string, file: object) => void} [opts.onProcessed]  called with the new
 *        Media id when the server accepts a file
 * @returns {object} the FilePond instance
 */
export function createMediaFilePond(input, { uploadUrl, csrfToken, onProcessed }) {
    registerPluginsOnce();

    // FilePond labels translated by the admin layout (window.__tallystI18n); English fallbacks.
    const FP = (window.__tallystI18n || {}).filePond || {};
    const pond = FilePond.create(input, {
        name: 'file',
        allowMultiple: true,
        allowRevert: false,
        credits: false,
        acceptedFileTypes: ACCEPTED_TYPES,
        maxFileSize: MAX_SIZE,
        labelIdle: FP.idle || 'Drag images here or <span class="filepond--label-action">browse</span>',
        labelFileTypeNotAllowed: FP.type_not_allowed || 'Only images are allowed (JPG, PNG, WEBP, GIF).',
        labelMaxFileSizeExceeded: FP.too_big || 'The file is too large.',
        labelMaxFileSize: FP.max_size || 'Maximum size is {filesize}.',
        server: {
            process: {
                url: uploadUrl,
                method: 'POST',
                headers: { 'X-CSRF-Token': csrfToken },
                // Server returns the new Media id as plain text → that becomes serverId.
                onload: (response) => response,
                onerror: (response) => response,
            },
            // We don't expose revert/restore/load/fetch — deletion goes through the CRUD.
            revert: null,
            restore: null,
            load: null,
            fetch: null,
        },
    });

    if (typeof onProcessed === 'function') {
        pond.on('processfile', (error, file) => {
            if (!error && file && file.serverId) {
                onProcessed(file.serverId, file);
            }
        });
    }

    return pond;
}
