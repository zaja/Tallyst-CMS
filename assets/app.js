/*
 * Front-end 'app' entrypoint (loaded via importmap('app') in the theme layout).
 * Uses the FRONT-ONLY Stimulus bootstrap so the public site never pulls the admin/editor
 * JS (chart.js, Tiptap/prosemirror, FilePond). Admin controllers live in stimulus_bootstrap.js,
 * loaded only by the admin entrypoint.
 */
import './front_bootstrap.js';
import './styles/app.css';
