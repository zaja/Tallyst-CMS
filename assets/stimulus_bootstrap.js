/*
 * ADMIN-ONLY Stimulus bootstrap (the EasyAdmin 'admin' entrypoint, via DashboardController::
 * configureAssets → assets/admin.js). Registers every admin/editor controller + the editor
 * wiring. ⚠ Do NOT import this from the FRONT 'app' entrypoint — that would drag chart.js /
 * prosemirror / filepond (~118 KiB) onto public pages. The front has its own minimal bootstrap
 * (assets/front_bootstrap.js, search--live only).
 */
import { startStimulusApp } from '@symfony/stimulus-bundle';
import FormbuilderConditionsController from '../modules/FormBuilder/assets/controllers/formbuilder_conditions_controller.js';
import FormbuilderBuilderController from '../modules/FormBuilder/assets/controllers/formbuilder_builder_controller.js';
import CountrySelectController from '../modules/FormBuilder/assets/controllers/country_select_controller.js';
import FormTypeController from '../modules/FormBuilder/assets/controllers/form_type_controller.js';
import FormWizardController from '../modules/FormBuilder/assets/controllers/form_wizard_controller.js';
import MorUnitController from '../modules/FormBuilder/assets/controllers/mor_unit_controller.js';
import MorPriceController from '../modules/FormBuilder/assets/controllers/mor_price_controller.js';
import MorImportController from '../modules/FormBuilder/assets/controllers/mor_import_controller.js';
import PaymentExclusiveController from '../modules/FormBuilder/assets/controllers/payment_exclusive_controller.js';
import FormRulesController from '../modules/FormBuilder/assets/controllers/form_rules_controller.js';
import DashboardChartController from '../modules/FormBuilder/assets/controllers/dashboard_chart_controller.js';
import WebhookCheckController from '../modules/FormBuilder/assets/controllers/webhook_check_controller.js';
import MediaLibraryController from '../modules/Media/assets/controllers/media_library_controller.js';
import MediaPickerController from '../modules/Media/assets/controllers/media_picker_controller.js';
import MediaBulkUploadController from '../modules/Media/assets/controllers/media_bulk_upload_controller.js';
import MediaCropExistingController from '../modules/Media/assets/controllers/media_crop_existing_controller.js';
import MediaTiptapController from '../modules/Media/assets/controllers/tiptap_controller.js';
import EmailEditorController from './admin/email_editor_controller.js';
import MenuCollapseController from './admin/menu_collapse_controller.js';
import { registerEditorExtension } from '../modules/Media/assets/tiptap_extensions.js';
import { FormEmbed } from '../modules/FormBuilder/assets/tiptap_form_node.js';
import { openFormPicker } from '../modules/FormBuilder/assets/form_picker.js';

const app = startStimulusApp();

// App-level editor wiring: FormBuilder plugs its [form] node + "Ubaci formu" button into
// the Tiptap editor without the Media editor referencing FormBuilder (IoC, like the PHP
// EditorShortcodeConverterInterface). The node is always registered so existing [form]
// embeds round-trip safely; the toolbar button is gated by the editor against the enabled
// modules list (so a disabled FormBuilder shows no button).
// Translations for this bootstrap-wired button live in a tiny global set by the admin layout
// (templates/bundles/EasyAdminBundle/layout.html.twig) — it can't read a per-page Twig element.
// English fallback so it never crashes if the global is somehow absent.
const fpI18n = (window.__tallystI18n || {}).formPicker || {};
registerEditorExtension({
    // key MUST match FormBuilderModule::getName() so enabled_modules() gating works.
    key: 'form_builder',
    node: FormEmbed,
    toolbar: { icon: 'fa-solid fa-table-list', label: fpI18n.insert || 'Insert form', title: fpI18n.insert || 'Insert form', action: (editor) => openFormPicker(editor) },
});

// FormBuilder module controllers (one of the 5 per-module app-side touch points; see CLAUDE.md).
app.register('formbuilder--conditions', FormbuilderConditionsController);
app.register('formbuilder--builder', FormbuilderBuilderController);
app.register('formbuilder--country-select', CountrySelectController);
app.register('formbuilder--formtype', FormTypeController);
app.register('formbuilder--wizard', FormWizardController);
app.register('formbuilder--mor-unit', MorUnitController);
app.register('formbuilder--mor-price', MorPriceController);
app.register('formbuilder--mor-import', MorImportController);
app.register('formbuilder--payment-exclusive', PaymentExclusiveController);
app.register('formbuilder--rules', FormRulesController);
app.register('formbuilder--dashboard-chart', DashboardChartController);
app.register('formbuilder--webhook-check', WebhookCheckController);

// Media module controllers: reusable library modal (grid + search + FilePond) and the
// featured-image picker widget that consumes its selection event.
app.register('media--library', MediaLibraryController);
app.register('media--picker', MediaPickerController);
app.register('media--bulk-upload', MediaBulkUploadController);
app.register('media--crop-existing', MediaCropExistingController);
app.register('media--tiptap', MediaTiptapController);

// App-level: Tiptap-lite editor for email template bodies (Pass 2). Raw HTML, no converter.
app.register('email-editor', EmailEditorController);

// Admin: collapsible SYSTEM sidebar section (default collapsed, state in localStorage).
app.register('admin--menu-collapse', MenuCollapseController);
