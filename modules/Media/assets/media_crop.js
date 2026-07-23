import Cropper from 'cropperjs';
import 'cropperjs/dist/cropper.min.css';
import './styles/crop.css';

/*
 * Local (client-side, no network) crop overlay shown over a just-selected file BEFORE it's
 * uploaded — entirely client-built (no server template, no DOM mount point), same pattern as
 * FormBuilder's form_picker.js. Triggered from filepond_factory.js's custom server.process,
 * which runs on EVERY Media upload entry point (bulk-upload page + the library-modal upload
 * zone) since both share that one factory.
 *
 * Cropping is an OFFER, not a requirement — the admin can crop, upload as-is, or cancel
 * entirely, which is why openCropper() resolves with a tagged action rather than just a rect.
 */

const i18n = () => (window.__tallystI18n || {}).crop || {};

// Cropper.js aspect ratio: a number, or NaN for free-form (its own convention).
const PRESETS = [
    { key: '16:9', ratio: 16 / 9, labelKey: 'preset16x9', fallback: '16:9 (Featured)' },
    { key: '1:1', ratio: 1, labelKey: 'preset1x1', fallback: '1:1 (Square)' },
    { key: 'free', ratio: NaN, labelKey: 'presetFree', fallback: 'Free' },
];

// Default footer action(s) that need the crop rect — the plain upload-time "Confirm crop"
// button. Existing-image callers (cropping an already-stored Media) pass their OWN
// confirmActions (e.g. "Save as new" + "Replace") instead — see openCropper below.
const DEFAULT_CONFIRM_ACTIONS = [
    { action: 'crop', labelKey: 'confirm', fallback: 'Confirm crop', variant: 'btn-primary' },
];

function buildModal(t, { showSkip = true, confirmActions = DEFAULT_CONFIRM_ACTIONS } = {}) {
    const backdrop = document.createElement('div');
    backdrop.className = 'media-crop__backdrop';
    const presetButtons = PRESETS.map((p) => (
        `<button type="button" class="btn btn-sm btn-secondary" data-preset="${p.key}">${t[p.labelKey] || p.fallback}</button>`
    )).join('');
    const confirmButtons = confirmActions.map((a) => (
        `<button type="button" class="btn ${a.variant || 'btn-primary'} media-crop__confirm" data-crop-action="${a.action}">${t[a.labelKey] || a.fallback}</button>`
    )).join('');
    backdrop.innerHTML = `
        <div class="media-crop__dialog" role="dialog" aria-modal="true">
            <div class="media-crop__header">
                <strong>${t.title || 'Crop image'}</strong>
                <button type="button" class="btn-close media-crop__close" aria-label="${t.cancel || 'Cancel'}"></button>
            </div>
            <div class="media-crop__presets">${presetButtons}</div>
            <div class="media-crop__canvas-wrap">
                <img class="media-crop__image" alt="">
            </div>
            <div class="media-crop__footer">
                <button type="button" class="btn btn-secondary media-crop__cancel">${t.cancel || 'Cancel'}</button>
                ${showSkip ? `<button type="button" class="btn btn-secondary media-crop__skip">${t.skip || 'Upload without crop'}</button>` : ''}
                ${confirmButtons}
            </div>
        </div>`;
    return backdrop;
}

/**
 * Open the crop overlay over an image. Accepts either a locally-selected `File`/`Blob`
 * (the upload-time path — nothing has reached the server yet) or a plain image URL string
 * (an already-stored image, e.g. cropping an existing Media item — Cropper.js works
 * directly off an `<img src>`, no blob needed).
 *
 * @param {File|Blob|string} source
 * @param {{allowSkip?: boolean, confirmActions?: Array<{action:string,labelKey:string,fallback:string,variant?:string}>}} [options]
 *   allowSkip (default true) shows the "upload without crop" button — irrelevant when
 *   cropping an already-stored image (there is nothing to "upload as-is"), so that caller
 *   passes `allowSkip: false` to omit it. confirmActions (default: a single "Confirm crop"
 *   button, the upload-time behaviour) lets a caller render one or more footer buttons that
 *   all resolve with the crop rect plus their own `action` tag — e.g. the existing-image
 *   caller passes two: "Save as new image" (action 'saveNew') and "Replace this image"
 *   (action 'replace'), so the admin's choice of WHAT to do with the crop travels back in
 *   the same resolved value as the rect itself.
 * @returns {Promise<{action:string,rect:{x:number,y:number,width:number,height:number}}|{action:'skip'}|{action:'cancel'}>}
 *   `rect` (present on every confirmActions outcome) is in the ORIGINAL image's pixel
 *   space, not the on-screen scaled preview.
 */
export function openCropper(source, { allowSkip = true, confirmActions = DEFAULT_CONFIRM_ACTIONS } = {}) {
    return new Promise((resolve) => {
        const t = i18n();
        const backdrop = buildModal(t, { showSkip: allowSkip, confirmActions });
        const imgEl = backdrop.querySelector('.media-crop__image');

        // A File/Blob is the upload-time path — nothing has reached the server, so it needs
        // an object URL to preview locally. A plain URL string (an already-stored image) is
        // used as-is; there's no blob to revoke for it.
        const isLocalFile = source instanceof Blob;
        const objectUrl = isLocalFile ? URL.createObjectURL(source) : null;

        let cropper = null;
        let settled = false;

        const cleanup = () => {
            if (cropper) {
                cropper.destroy();
                cropper = null;
            }
            if (objectUrl) {
                URL.revokeObjectURL(objectUrl);
            }
            backdrop.remove();
            document.body.style.overflow = '';
        };

        const finish = (value) => {
            if (settled) {
                return;
            }
            settled = true;
            cleanup();
            resolve(value);
        };

        backdrop.addEventListener('click', (e) => {
            if (e.target === backdrop) {
                finish({ action: 'cancel' });
            }
        });
        backdrop.querySelector('.media-crop__close').addEventListener('click', () => finish({ action: 'cancel' }));
        backdrop.querySelector('.media-crop__cancel').addEventListener('click', () => finish({ action: 'cancel' }));
        const skipBtn = backdrop.querySelector('.media-crop__skip');
        if (skipBtn) {
            skipBtn.addEventListener('click', () => finish({ action: 'skip' }));
        }

        backdrop.querySelectorAll('[data-preset]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const preset = PRESETS.find((p) => p.key === btn.dataset.preset);
                if (cropper && preset) {
                    cropper.setAspectRatio(preset.ratio);
                }
                backdrop.querySelectorAll('[data-preset]').forEach((b) => b.classList.toggle('active', b === btn));
            });
        });

        backdrop.querySelectorAll('.media-crop__confirm').forEach((btn) => {
            btn.addEventListener('click', () => {
                if (!cropper) {
                    return;
                }
                // getData(true) = rounded integers, in the ORIGINAL (natural) image's pixel
                // space — NOT the on-screen scaled preview. This is what the server-side Liip
                // crop needs.
                const data = cropper.getData(true);
                const rect = { x: data.x, y: data.y, width: data.width, height: data.height };
                finish({ action: btn.dataset.cropAction, rect });
            });
        });

        document.body.appendChild(backdrop);
        document.body.style.overflow = 'hidden';

        imgEl.addEventListener('load', () => {
            cropper = new Cropper(imgEl, {
                aspectRatio: PRESETS[0].ratio,
                viewMode: 1,
                autoCropArea: 1,
                background: false,
            });
            backdrop.querySelector('[data-preset="16:9"]').classList.add('active');
        }, { once: true });
        imgEl.src = objectUrl ?? source;
    });
}
