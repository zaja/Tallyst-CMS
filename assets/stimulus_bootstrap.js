import { startStimulusApp } from '@symfony/stimulus-bundle';
import FormbuilderConditionsController from '../modules/FormBuilder/assets/controllers/formbuilder_conditions_controller.js';
import FormbuilderBuilderController from '../modules/FormBuilder/assets/controllers/formbuilder_builder_controller.js';
import MediaLibraryController from '../modules/Media/assets/controllers/media_library_controller.js';
import MediaPickerController from '../modules/Media/assets/controllers/media_picker_controller.js';
import MediaBulkUploadController from '../modules/Media/assets/controllers/media_bulk_upload_controller.js';
import MediaTiptapController from '../modules/Media/assets/controllers/tiptap_controller.js';

const app = startStimulusApp();

// FormBuilder module controllers (one of the 5 per-module app-side touch points; see CLAUDE.md).
app.register('formbuilder--conditions', FormbuilderConditionsController);
app.register('formbuilder--builder', FormbuilderBuilderController);

// Media module controllers: reusable library modal (grid + search + FilePond) and the
// featured-image picker widget that consumes its selection event.
app.register('media--library', MediaLibraryController);
app.register('media--picker', MediaPickerController);
app.register('media--bulk-upload', MediaBulkUploadController);
app.register('media--tiptap', MediaTiptapController);
