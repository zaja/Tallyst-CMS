import { startStimulusApp } from '@symfony/stimulus-bundle';
import FormbuilderConditionsController from '../modules/FormBuilder/assets/controllers/formbuilder_conditions_controller.js';
import FormbuilderBuilderController from '../modules/FormBuilder/assets/controllers/formbuilder_builder_controller.js';
import MediaLibraryController from '../modules/Media/assets/controllers/media_library_controller.js';
import MediaPickerController from '../modules/Media/assets/controllers/media_picker_controller.js';
import MediaBulkUploadController from '../modules/Media/assets/controllers/media_bulk_upload_controller.js';
import MediaTiptapController from '../modules/Media/assets/controllers/tiptap_controller.js';
import { registerEditorExtension } from '../modules/Media/assets/tiptap_extensions.js';
import { FormEmbed } from '../modules/FormBuilder/assets/tiptap_form_node.js';
import { openFormPicker } from '../modules/FormBuilder/assets/form_picker.js';

const app = startStimulusApp();

// App-level editor wiring: FormBuilder plugs its [form] node + "Ubaci formu" button into
// the Tiptap editor without the Media editor referencing FormBuilder (IoC, like the PHP
// EditorShortcodeConverterInterface). The node is always registered so existing [form]
// embeds round-trip safely; the toolbar button is gated by the editor against the enabled
// modules list (so a disabled FormBuilder shows no button).
registerEditorExtension({
    // key MUST match FormBuilderModule::getName() so enabled_modules() gating works.
    key: 'form_builder',
    node: FormEmbed,
    toolbar: { label: '📋 Forma', title: 'Ubaci formu', action: (editor) => openFormPicker(editor) },
});

// FormBuilder module controllers (one of the 5 per-module app-side touch points; see CLAUDE.md).
app.register('formbuilder--conditions', FormbuilderConditionsController);
app.register('formbuilder--builder', FormbuilderBuilderController);

// Media module controllers: reusable library modal (grid + search + FilePond) and the
// featured-image picker widget that consumes its selection event.
app.register('media--library', MediaLibraryController);
app.register('media--picker', MediaPickerController);
app.register('media--bulk-upload', MediaBulkUploadController);
app.register('media--tiptap', MediaTiptapController);
