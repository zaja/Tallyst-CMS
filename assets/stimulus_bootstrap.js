import { startStimulusApp } from '@symfony/stimulus-bundle';
import FormbuilderConditionsController from '../modules/FormBuilder/assets/controllers/formbuilder_conditions_controller.js';
import FormbuilderBuilderController from '../modules/FormBuilder/assets/controllers/formbuilder_builder_controller.js';

const app = startStimulusApp();

// FormBuilder module controllers (one of the 5 per-module app-side touch points; see CLAUDE.md).
app.register('formbuilder--conditions', FormbuilderConditionsController);
app.register('formbuilder--builder', FormbuilderBuilderController);
