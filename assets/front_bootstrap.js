import { startStimulusApp } from '@symfony/stimulus-bundle';
import SearchLiveController from './front/search_live_controller.js';
import FormbuilderCopyController from '../modules/FormBuilder/assets/controllers/formbuilder_copy_controller.js';

/*
 * FRONT-ONLY Stimulus bootstrap (the public 'app' entrypoint).
 *
 * The public site uses TWO Stimulus controllers: the header live-search dropdown, and the
 * copy-to-clipboard button on a member's licence key. Both are dependency-free.
 *
 * All admin/editor controllers (Tiptap, Chart.js, FilePond, form builder, email editor,
 * menu-collapse…) and the editor wiring live in assets/stimulus_bootstrap.js, which is
 * loaded ONLY by the admin entrypoint (assets/admin.js). Keeping them out of here is what
 * stops the front bundle from transitively pulling chart.js / prosemirror / filepond
 * (~118 KiB) via AssetMapper's recursive preload.
 *
 * ⚠ Add a controller here ONLY if a genuine FRONT feature needs it. Never import an
 * admin/editor controller into this file. A MODULE may register a front controller here —
 * FormBuilder's copy button is the precedent — but only if it pulls no dependencies.
 */
const app = startStimulusApp();
app.register('search--live', SearchLiveController);
app.register('formbuilder--copy', FormbuilderCopyController);
