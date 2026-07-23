import * as FilePond from 'filepond';
import FilePondPluginImagePreview from 'filepond-plugin-image-preview';
import FilePondPluginFileValidateType from 'filepond-plugin-file-validate-type';
import FilePondPluginFileValidateSize from 'filepond-plugin-file-validate-size';
import 'filepond/dist/filepond.min.css';
import 'filepond-plugin-image-preview/dist/filepond-plugin-image-preview.min.css';
import { openCropper } from './media_crop.js';

/*
 * Shared FilePond setup for the Media module — used by BOTH the library modal upload
 * zone and the bulk-upload page, so the client-side rules stay in one place. Client
 * validation MIRRORS the server (Assert\Image: raster only, ≤5 MB); the server remains
 * the authority (MediaUploader re-validates the same Assert\Image).
 *
 * Cropping (step 1 of the feature): every selected file is intercepted BEFORE upload —
 * FilePond's `server.process` option runs as a CUSTOM FUNCTION (not the plain url/headers
 * object) so nothing is sent to the server until the crop overlay resolves. Applies to both
 * consumers automatically since they share this one factory. Cropping is an OFFER: the admin
 * can confirm a crop, upload as-is ("Upload without crop"), or cancel — see media_crop.js.
 * On confirm, the crop rect travels as extra crop_x/crop_y/crop_w/crop_h form fields
 * alongside the file in the SAME multipart request (read + validated server-side by
 * MediaLibraryController before MediaUploader ever sees them).
 *
 * ⚠ onQueueSettled (not a per-file callback): consumers used to react to EACH file's
 * 'processfile' event with a debounced reload/refetch, assuming a multi-file drop finishes
 * in a tight burst (true before cropping existed). Now that each file waits on a HUMAN crop
 * decision, the gap between file 1 finishing and file 2/3 even opening their overlay can be
 * arbitrarily long — a debounce timer armed after file 1 fires a reload/refetch WHILE file
 * 2/3 are still pending, wiping their in-flight state (a full page reload for the bulk zone;
 * pond.removeFiles() for the modal). onQueueSettled fixes this by firing EXACTLY ONCE, only
 * once every file handed to THIS pond instance has fully settled (crop-uploaded, skip-
 * uploaded, cancelled, or errored) — tracked with our own per-instance counter rather than
 * trusting FilePond's own 'processfiles'/DID_COMPLETE_ITEM_PROCESSING_ALL event, which is
 * keyed to its PROCESSING_COMPLETE status and wouldn't reliably include our cancel path
 * (cancel goes through error() + removeFile(), not a normal completion).
 */

// Mirror of the entity's Assert\Image mimeTypes + maxSize.
const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
const MAX_SIZE = '5MB';

// Serializes the CROP OVERLAY (not the uploads themselves) across every file added to any
// FilePond instance created by this factory, so dropping several files at once shows the
// cropper one file at a time instead of stacking overlays. Once a file's overlay resolves,
// its actual upload proceeds independently — it doesn't wait for the next file's decision.
let cropQueue = Promise.resolve();

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
 * @param {() => void} [opts.onQueueSettled]  called ONCE, when every file added to this
 *        pond instance has fully settled (uploaded, cancelled, or errored) — never per file.
 * @returns {object} the FilePond instance
 */
export function createMediaFilePond(input, { uploadUrl, csrfToken, onQueueSettled }) {
    registerPluginsOnce();

    // FilePond labels translated by the admin layout (window.__tallystI18n); English fallbacks.
    const FP = (window.__tallystI18n || {}).filePond || {};

    // Declared before create() so the process() closure below can call pond.removeFile() on a
    // cancelled file — the closure only ever RUNS later (on file-select), by which point
    // create() has already returned and assigned it.
    let pond;

    // Per-instance count of files currently mid-flow (from process() being called to that
    // file fully settling, whichever way). onQueueSettled fires when this hits 0 again.
    let pendingCount = 0;

    pond = FilePond.create(input, {
        // No `name` on purpose: `name` ONLY drives the hidden form-submit input FilePond injects,
        // and here uploads go via server.process (AJAX) + onQueueSettled — the file is NEVER
        // form-submitted (MediaLibraryController::firstUploadedFile reads it regardless of field
        // name). A named hidden input inside a guarded <form> (the Branding settings pickers)
        // appears AFTER admin_dirty_guard's snapshot → a false "unsaved changes" beforeunload.
        // Omitting it fixes that.
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
            // Custom function instead of the plain url/headers object — this is what lets us
            // hold the file back and show the crop overlay before anything reaches the network,
            // and attach the crop rect as extra fields on the SAME upload request.
            process: (fieldName, file, metadata, load, error, progress, abort) => {
                let cancelled = false;
                let xhr = null;
                let settledForCount = false;

                pendingCount += 1;

                // Marks THIS file done for the pending count — safe to call more than once
                // (e.g. an external abort() after we already reached load()/error()).
                const noteSettled = () => {
                    if (settledForCount) {
                        return;
                    }
                    settledForCount = true;
                    pendingCount -= 1;
                    if (pendingCount === 0 && typeof onQueueSettled === 'function') {
                        onQueueSettled();
                    }
                };

                // Chain onto the shared queue so this file's overlay only opens once the
                // previous file's has been resolved (see the cropQueue comment above).
                const myTurn = cropQueue.then(() => (cancelled ? { action: 'cancel' } : openCropper(file)));
                cropQueue = myTurn.then(() => undefined, () => undefined);

                myTurn.then((outcome) => {
                    if (cancelled) {
                        noteSettled();
                        return;
                    }

                    if (!outcome || outcome.action === 'cancel') {
                        // error() BEFORE removeFile(): FilePond only frees its internal
                        // concurrency slot (default maxParallelUploads: 2) and drains its
                        // OWN queue of files waiting behind this one when load()/error()/its
                        // own abort() fires — removeFile() alone just archives the item and
                        // does neither. Skipping error() here left the slot "held" forever
                        // whenever a cancel landed among the first 2 concurrent files, so any
                        // 3rd+ file behind it in FilePond's queue never got its process()
                        // called at all. The error message never reaches the UI — removeFile()
                        // on the very next line takes the item out before a render happens.
                        error('cancelled');
                        pond.removeFile(file.id);
                        noteSettled();
                        return;
                    }

                    const formData = new FormData();
                    formData.append('file', file, file.name);
                    if (outcome.action === 'crop' && outcome.rect) {
                        formData.append('crop_x', outcome.rect.x);
                        formData.append('crop_y', outcome.rect.y);
                        formData.append('crop_w', outcome.rect.width);
                        formData.append('crop_h', outcome.rect.height);
                    }

                    xhr = new XMLHttpRequest();
                    xhr.open('POST', uploadUrl, true);
                    xhr.setRequestHeader('X-CSRF-Token', csrfToken);
                    xhr.upload.onprogress = (e) => {
                        if (e.lengthComputable) {
                            progress(true, e.loaded, e.total);
                        }
                    };
                    xhr.onload = () => {
                        if (xhr.status >= 200 && xhr.status < 300) {
                            // Server returns the new Media id as plain text → becomes serverId.
                            load(xhr.responseText);
                        } else {
                            error(xhr.responseText || `HTTP ${xhr.status}`);
                        }
                        noteSettled();
                    };
                    xhr.onerror = () => {
                        error('Network error.');
                        noteSettled();
                    };
                    xhr.send(formData);
                });

                return {
                    abort: () => {
                        cancelled = true;
                        if (xhr) {
                            xhr.abort();
                        }
                        abort();
                        noteSettled();
                    },
                };
            },
            // We don't expose revert/restore/load/fetch — deletion goes through the CRUD.
            revert: null,
            restore: null,
            load: null,
            fetch: null,
        },
    });

    return pond;
}
