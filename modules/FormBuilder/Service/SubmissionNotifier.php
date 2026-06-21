<?php

namespace Tallyst\FormBuilder\Service;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\FormSubmission;

/**
 * Sends a notification e-mail for a FREE form submission when the form has notifications
 * enabled. It is async by virtue of $mailer->send(): the app routes SendEmailMessage to the
 * Messenger worker (same path as the order mails), so the submit response isn't blocked and
 * the send is retriable. From is left UNSET so DefaultFromListener applies the configured
 * mail_from_email — hardcoding a From the SMTP account doesn't own gets rejected (553).
 *
 * Priced forms are skipped: their submission goes through the order/fulfilment flow.
 */
class SubmissionNotifier
{
    public function __construct(private readonly MailerInterface $mailer)
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

        $email = (new Email())
            ->to(...$recipients)
            ->subject($form->getNotifySubject() ?: 'Nova prijava: '.$form->getName())
            ->text($this->body($form, $submission));

        $this->mailer->send($email);
    }

    private function body(FormDefinition $form, FormSubmission $submission): string
    {
        $labels = [];
        foreach ($form->getFields() as $field) {
            $labels[$field->getKey()] = $field->getLabel();
        }

        $lines = ['Nova prijava forme "'.$form->getName().'".', ''];
        foreach ($submission->getData() as $key => $value) {
            $value = is_array($value) ? implode(', ', $value) : (string) $value;
            $lines[] = ($labels[$key] ?? $key).': '.$value;
        }

        return implode("\n", $lines);
    }
}
