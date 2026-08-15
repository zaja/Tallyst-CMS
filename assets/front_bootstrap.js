import { startStimulusApp } from '@symfony/stimulus-bundle';
import SearchLiveController from './front/search_live_controller.js';
import FormbuilderCopyController from '../modules/FormBuilder/assets/controllers/formbuilder_copy_controller.js';
import FormbuilderConditionsController from '../modules/FormBuilder/assets/controllers/formbuilder_conditions_controller.js';

/*
 * FRONT-ONLY Stimulus bootstrap (the public 'app' entrypoint).
 *
 * The public site uses THREE Stimulus controllers: the header live-search dropdown, the
 * copy-to-clipboard button on a member's licence key, and conditional form fields. All are
 * dependency-free — the conditions controller brings only condition_evaluator.js (6.2 KiB together,
 * measured), which imports nothing.
 *
 * ⚠ formbuilder--conditions BELONGS HERE AND WAS LOST FROM HERE ONCE. It was registered only in the
 * admin bootstrap, so when this split was introduced (2026-07-05) conditional fields silently
 * stopped working on every public form and stayed broken for six weeks. Nothing failed: the SERVER
 * kept evaluating conditions correctly, so no PHP test could see it — the visitor was simply asked a
 * question that did not apply and could not submit without answering it.
 * FrontStimulusRegistrationTest now compares what the public templates ASK FOR against what is
 * registered here, so the next split cannot repeat it.
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
app.register('formbuilder--conditions', FormbuilderConditionsController);
