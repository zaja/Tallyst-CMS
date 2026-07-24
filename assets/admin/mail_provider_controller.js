import { Controller } from '@hotwired/stimulus';

/*
 * Postavke → Email → Delivery: shows only the active mail provider's fields. Fully DATA-driven
 * (no per-provider branch, unlike formbuilder--formtype's fixed named targets) — each field row
 * carries data-mail-provider-field="<providerKey>" (set server-side from MailProviderRegistry,
 * see SettingsController::formOptions()), and this controller just shows the ones matching the
 * <select>'s current value. Adding a 5th/6th provider needs zero changes here: it's a new
 * MailProviderRegistry entry, which tags its own fields automatically.
 *
 * Pure presentation, same discipline as form_type_controller.js: every field stays in the DOM
 * (hidden via a class, never removed/disabled), so a hidden field still submits its current
 * value and switching providers back and forth never clears another provider's stored values.
 */
export default class extends Controller {
    static targets = ['select', 'field'];

    connect() {
        this.apply();
    }

    apply() {
        const provider = this.hasSelectTarget ? this.selectTarget.value : '';
        this.fieldTargets.forEach((el) => {
            el.classList.toggle('d-none', el.dataset.mailProviderField !== provider);
        });
    }
}
