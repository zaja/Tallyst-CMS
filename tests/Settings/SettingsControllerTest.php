<?php

namespace App\Tests\Settings;

use App\Controller\Admin\SettingsController;
use App\Settings\SettingsManager;
use App\Settings\SettingsRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The test-mail message is sent straight to the transport (bypassing the Messenger bus), so
 * the From identity must be set EXPLICITLY from settings — DefaultFromListener's bus-only
 * DelayedEnvelope path isn't in play, and an empty From would make Envelope::create() throw
 * (and some SMTP servers reject a missing From).
 */
class SettingsControllerTest extends TestCase
{
    /**
     * @param array<string, mixed> $settings
     */
    private function controllerFor(array $settings): object
    {
        $manager = $this->createStub(SettingsManager::class);
        $manager->method('get')->willReturnCallback(fn (string $key) => $settings[$key] ?? null);

        return new class($this->createStub(SettingsRegistry::class), $manager, $this->createStub(TranslatorInterface::class)) extends SettingsController {
            public function buildTestEmailPublic(string $to): Email
            {
                return $this->buildTestEmail($to);
            }
        };
    }

    public function testTestEmailUsesConfiguredFromIdentity(): void
    {
        $email = $this->controllerFor([
            'mail_from_email' => 'noreply@site.test',
            'mail_from_name' => 'Tallyst',
            'mail_reply_to' => 'hello@site.test',
        ])->buildTestEmailPublic('rcpt@example.test');

        $from = $email->getFrom()[0];
        self::assertSame('noreply@site.test', $from->getAddress());
        self::assertSame('Tallyst', $from->getName());
        self::assertSame('rcpt@example.test', $email->getTo()[0]->getAddress());
        self::assertSame('hello@site.test', $email->getReplyTo()[0]->getAddress());
    }

    public function testFromFallsBackToRecipientWhenNoSenderConfigured(): void
    {
        $email = $this->controllerFor([
            'mail_from_email' => '',
            'mail_from_name' => '',
            'mail_reply_to' => '',
        ])->buildTestEmailPublic('rcpt@example.test');

        self::assertSame('rcpt@example.test', $email->getFrom()[0]->getAddress(), 'From is never empty');
        self::assertSame([], $email->getReplyTo(), 'no Reply-To when none configured');
    }
}
