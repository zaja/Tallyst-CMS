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

function buildModal(t) {
    const backdrop = document.createElement('div');
    backdrop.className = 'media-crop__backdrop';
    const presetButtons = PRESETS.map((p) => (
        `<button type="button" class="btn btn-sm btn-secondary" data-preset="${p.key}">${t[p.labelKey] || p.fallback}</button>`
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
                <button type="button" class="btn btn-secondary media-crop__skip">${t.skip || 'Upload without crop'}</button>
                <button type="button" class="btn btn-primary media-crop__confirm">${t.confirm || 'Confirm crop'}</button>
            </div>
        </div>`;
    return backdrop;
}

/**
 * Open the crop overlay on a locally-selected file. Cropping is OPTIONAL — the admin can
 * confirm a crop, upload the file as-is, or cancel the file entirely.
 *
 * @param {File} file
 * @returns {Promise<{action:'crop',rect:{x:number,y:number,width:number,height:number}}|{action:'skip'}|{action:'cancel'}>}
 *   `rect` (when action is 'crop') is in the ORIGINAL image's pixel space, not the on-screen
 *   scaled preview.
 */
export function openCropper(file) {
    return new Promise((resolve) => {
        const t = i18n();
        const backdrop = buildModal(t);
        const imgEl = backdrop.querySelector('.media-crop__image');
        const objectUrl = URL.createObjectURL(file);

        let cropper = null;
        let settled = false;

        const cleanup = () => {
            if (cropper) {
                cropper.destroy();
                cropper = null;
            }
            URL.revokeObjectURL(objectUrl);
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
        backdrop.querySelector('.media-crop__skip').addEventListener('click', () => finish({ action: 'skip' }));

        backdrop.querySelectorAll('[data-preset]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const preset = PRESETS.find((p) => p.key === btn.dataset.preset);
                if (cropper && preset) {
                    cropper.setAspectRatio(preset.ratio);
                }
                backdrop.querySelectorAll('[data-preset]').forEach((b) => b.classList.toggle('active', b === btn));
            });
        });

        backdrop.querySelector('.media-crop__confirm').addEventListener('click', () => {
            if (!cropper) {
                return;
            }
            // getData(true) = rounded integers, in the ORIGINAL (natural) image's pixel space —
            // NOT the on-screen scaled preview. This is what the server-side Liip crop needs.
            const data = cropper.getData(true);
            const rect = { x: data.x, y: data.y, width: data.width, height: data.height };
            finish({ action: 'crop', rect });
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
        imgEl.src = objectUrl;
    });
}
