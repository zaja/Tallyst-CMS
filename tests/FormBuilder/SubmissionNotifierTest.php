<?php

namespace App\Tests\FormBuilder;

use App\Email\EmailSender;
use PHPUnit\Framework\TestCase;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\FormField;
use Tallyst\FormBuilder\Entity\FormType;
use Tallyst\FormBuilder\Entity\FormSubmission;
use Tallyst\FormBuilder\Service\SubmissionNotifier;

/**
 * The notifier now delegates to the EmailSender engine (form_notification type). These assert it
 * calls the engine with the right type / tags / recipients / per-form subject override — and that
 * it does NOT send for disabled / no-recipient / priced forms.
 */
class SubmissionNotifierTest extends TestCase
{
    private function freeForm(): FormDefinition
    {
        $form = (new FormDefinition())->setName('Kontakt')
            ->setNotifyEnabled(true)
            ->setNotifyRecipient('inbox@site.test');
        $form->addField((new FormField())->setKey('name')->setLabel('Ime')->setType(FormField::TYPE_TEXT));

        return $form;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function submission(FormDefinition $form, array $data): FormSubmission
    {
        return (new FormSubmission())->setForm($form)->setData($data);
    }

    public function testSendsNotificationForEnabledFreeForm(): void
    {
        $args = null;
        $sender = $this->createMock(EmailSender::class);
        $sender->expects(self::once())->method('send')
            ->willReturnCallback(function (...$a) use (&$args): void { $args = $a; });

        (new SubmissionNotifier($sender))->notify($this->submission($this->freeForm(), ['name' => 'Ana']));

        [$type, $tags, $to, $subjectOverride] = $args;
        self::assertSame('form_notification', $type);
        self::assertSame('Kontakt', $tags['form_name']);
        self::assertStringContainsString('Ime: Ana', $tags['submission_summary']);
        self::assertSame(['inbox@site.test'], $to);
        self::assertNull($subjectOverride, 'no per-form subject → engine default');
    }

    public function testUsesConfiguredSubjectAndMultipleRecipients(): void
    {
        $form = $this->freeForm()->setNotifyRecipient('a@x.test, b@y.test')->setNotifySubject('Upit!');
        $args = null;
        $sender = $this->createMock(EmailSender::class);
        $sender->expects(self::once())->method('send')
            ->willReturnCallback(function (...$a) use (&$args): void { $args = $a; });

        (new SubmissionNotifier($sender))->notify($this->submission($form, []));

        [, , $to, $subjectOverride] = $args;
        self::assertSame(['a@x.test', 'b@y.test'], $to);
        self::assertSame('Upit!', $subjectOverride, 'per-form notifySubject preserved as override');
    }

    public function testDoesNotSendWhenDisabled(): void
    {
        $sender = $this->createMock(EmailSender::class);
        $sender->expects(self::never())->method('send');

        (new SubmissionNotifier($sender))->notify($this->submission($this->freeForm()->setNotifyEnabled(false), []));
    }

    public function testDoesNotSendWhenNoValidRecipient(): void
    {
        $sender = $this->createMock(EmailSender::class);
        $sender->expects(self::never())->method('send');

        (new SubmissionNotifier($sender))->notify($this->submission($this->freeForm()->setNotifyRecipient('not-an-email'), []));
    }

    public function testDoesNotSendForPricedForm(): void
    {
        $sender = $this->createMock(EmailSender::class);
        $sender->expects(self::never())->method('send');

        // A product form is now decided by the explicit type (not the price) — a product skips the notify.
        (new SubmissionNotifier($sender))->notify($this->submission($this->freeForm()->setFormType(FormType::DIGITAL)->setPriceMinor(500), []));
    }
}
