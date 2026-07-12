import { Controller } from '@hotwired/stimulus';

/*
 * Create-form wizard. Reveals the questions progressively and enables "Continue" once a complete path is
 * chosen — UI only; the server maps the answers to formType + morProvider (FormBuilderController).
 *
 *   Q1 messages           → done (a free form)
 *   Q1 sells → Q2 physical → done
 *   Q1 sells → Q2 digital  → Q3 self                → done
 *   Q1 sells → Q2 digital  → Q3 provider → Q4 which → done
 *   (physical never asks Q3/Q4 — it can't be a Merchant-of-Record)
 */
export default class extends Controller {
    static targets = ['q2block', 'q3block', 'q4block', 'submit'];

    connect() {
        this.change();
    }

    /** Any radio change → recompute which questions show and whether the path is complete. */
    change() {
        const q1 = this.chosen('q1');
        const q2 = this.chosen('q2');
        const q3 = this.chosen('q3');
        const q4 = this.chosen('q4');
        const sells = 'sells' === q1;
        const digital = sells && 'digital' === q2;
        const providerMor = digital && 'mor' === q3; // Q4 "which provider" only on the provider path

        this.q2blockTarget.classList.toggle('d-none', !sells);
        this.q3blockTarget.classList.toggle('d-none', !digital);
        this.q4blockTarget.classList.toggle('d-none', !providerMor);

        let complete = false;
        if ('messages' === q1) {
            complete = true;
        } else if (sells && 'physical' === q2) {
            complete = true;
        } else if (digital && 'self' === q3) {
            complete = true;
        } else if (providerMor && q4) {
            complete = true; // a provider must be picked
        }
        this.submitTarget.disabled = !complete;
    }

    chosen(name) {
        const el = this.element.querySelector(`input[name="${name}"]:checked`);

        return el ? el.value : null;
    }
}
