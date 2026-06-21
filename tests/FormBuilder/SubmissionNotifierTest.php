<?php

namespace App\Tests\FormBuilder;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\FormField;
use Tallyst\FormBuilder\Entity\FormSubmission;
use Tallyst\FormBuilder\Service\SubmissionNotifier;

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
        $captured = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')
            ->willReturnCallback(function (RawMessage $m) use (&$captured): void { $captured = $m; });

        (new SubmissionNotifier($mailer))->notify($this->submission($this->freeForm(), ['name' => 'Ana']));

        self::assertInstanceOf(Email::class, $captured);
        self::assertSame('inbox@site.test', $captured->getTo()[0]->getAddress());
        self::assertSame([], $captured->getFrom(), 'From left empty so DefaultFromListener applies mail_from_email');
        self::assertSame('Nova prijava: Kontakt', $captured->getSubject());
        self::assertStringContainsString('Ime: Ana', (string) $captured->getTextBody());
    }

    public function testUsesConfiguredSubjectAndMultipleRecipients(): void
    {
        $form = $this->freeForm()->setNotifyRecipient('a@x.test, b@y.test')->setNotifySubject('Upit!');
        $captured = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')
            ->willReturnCallback(function (RawMessage $m) use (&$captured): void { $captured = $m; });

        (new SubmissionNotifier($mailer))->notify($this->submission($form, []));

        self::assertInstanceOf(Email::class, $captured);
        self::assertSame('Upit!', $captured->getSubject());
        self::assertCount(2, $captured->getTo());
    }

    public function testDoesNotSendWhenDisabled(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        (new SubmissionNotifier($mailer))->notify($this->submission($this->freeForm()->setNotifyEnabled(false), []));
    }

    public function testDoesNotSendWhenNoValidRecipient(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        (new SubmissionNotifier($mailer))->notify($this->submission($this->freeForm()->setNotifyRecipient('not-an-email'), []));
    }

    public function testDoesNotSendForPricedForm(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        (new SubmissionNotifier($mailer))->notify($this->submission($this->freeForm()->setPriceMinor(500), []));
    }
}
