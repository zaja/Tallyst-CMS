import { Controller } from '@hotwired/stimulus';

/*
 * Create-form wizard (Faza 4). Reveals the questions progressively and enables "Continue" once a complete
 * path is chosen — UI only; the server maps the answers to the formType (FormBuilderController::wizardType).
 *
 *   Q1 messages           → done (a free form)
 *   Q1 sells → Q2 physical → done
 *   Q1 sells → Q2 digital  → Q3 self | mor → done   (physical never asks Q3 — it can't be MoR)
 */
export default class extends Controller {
    static targets = ['q2block', 'q3block', 'submit'];

    connect() {
        this.change();
    }

    /** Any radio change → recompute which questions show and whether the path is complete. */
    change() {
        const q1 = this.chosen('q1');
        const q2 = this.chosen('q2');
        const q3 = this.chosen('q3');
        const sells = 'sells' === q1;
        const digital = sells && 'digital' === q2;

        this.q2blockTarget.classList.toggle('d-none', !sells);
        this.q3blockTarget.classList.toggle('d-none', !digital);

        let complete = false;
        if ('messages' === q1) {
            complete = true;
        } else if (sells && 'physical' === q2) {
            complete = true;
        } else if (digital && q3) {
            complete = true;
        }
        this.submitTarget.disabled = !complete;
    }

    chosen(name) {
        const el = this.element.querySelector(`input[name="${name}"]:checked`);

        return el ? el.value : null;
    }
}
