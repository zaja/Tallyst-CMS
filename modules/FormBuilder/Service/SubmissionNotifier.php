<?php

namespace Tallyst\FormBuilder\Service;

use App\Email\EmailSender;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\FormSubmission;

/**
 * Sends a notification e-mail for a FREE form submission when the form has notifications
 * enabled. Goes through the EmailSender engine (editable `form_notification` template): async
 * via the worker, From left unset (DefaultFromListener / 553 lesson). The form's own
 * notifySubject, if set, is preserved as a per-send subject override.
 *
 * Priced forms are skipped: their submission goes through the order/fulfilment flow.
 */
class SubmissionNotifier
{
    public function __construct(private readonly EmailSender $emails)
    {
    }

    public function notify(FormSubmission $submission): void
    {
        $form = $submission->getForm();
        if (null === $form || $form->isProduct() || !$form->isNotifyEnabled()) {
            return;
        }

        $recipients = array_values(array_filter(
            $form->getNotifyRecipientList(),
            static fn (string $r): bool => false !== filter_var($r, \FILTER_VALIDATE_EMAIL),
        ));
        if ([] === $recipients) {
            return;
        }

        $this->emails->send(
            'form_notification',
            [
                'form_name' => $form->getName(),
                'submission_summary' => $this->summary($form, $submission),
            ],
            $recipients,
            $form->getNotifySubject() ?: null,
        );
    }

    private function summary(FormDefinition $form, FormSubmission $submission): string
    {
        $labels = [];
        foreach ($form->getFields() as $field) {
            $labels[$field->getKey()] = $field->getLabel();
        }

        $lines = [];
        foreach ($submission->getData() as $key => $value) {
            $value = is_array($value) ? implode(', ', $value) : (string) $value;
            $lines[] = ($labels[$key] ?? $key).': '.$value;
        }

        return implode("\n", $lines);
    }
}
