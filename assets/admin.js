/*
 * Admin entrypoint. Loads ONLY the Stimulus controllers (no front-end CSS), so the
 * EasyAdmin theme — including its light/dark mode — is never overridden by the app's
 * front-end styles. Wired into EasyAdmin via DashboardController::configureAssets().
 */
import './stimulus_bootstrap.js';
