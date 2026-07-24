<?php

namespace App\Tests\Readiness;

use App\Mailer\MailProviderRegistry;
use App\Mailer\SettingsMailerTransport;
use App\Readiness\Check;
use App\Readiness\ConfigReadinessProvider;
use App\Readiness\Status;
use App\Settings\SettingsManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ConfigReadinessProviderTest extends TestCase
{
    /**
     * @param array<string, string> $env
     * @param array<string, string> $settings
     */
    private function provider(array $env = [], array $settings = [], bool $smtpReadable = true): ConfigReadinessProvider
    {
        $sm = $this->createStub(SettingsManager::class);
        $sm->method('get')->willReturnCallback(static fn (string $k): string => $settings[$k] ?? '');
        $sm->method('isEncryptedValueReadable')->willReturn($smtpReadable);

        // Passthrough translator (returns the key, with any %param% VALUES appended) — env-var
        // LABELS stay literal (APP_SECRET…), while group + descriptive label/detail/fix surface
        // as their `admin` keys. Tests assert STATUS + the key (not localised text), except
        // where a test explicitly wants to confirm a parameter (e.g. the active provider name)
        // was actually passed through — the appended values make that checkable too.
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []): string => $id.($parameters ? ' '.implode(' ', $parameters) : ''),
        );

        // A REAL SettingsMailerTransport, sharing the SAME SettingsManager stub, so the
        // readiness check and the transport agree on the exact same "is it configured" answer
        // (the whole point of Part B: reuse, not re-derive). None of its other dependencies
        // (inner/factory/em/dispatcher/logger) are exercised by the 3 methods this test uses.
        $mailer = new SettingsMailerTransport(
            $this->createStub(TransportInterface::class),
            $sm,
            new EventDispatcher(),
            new MailProviderRegistry(),
            new Transport([]),
            $this->createStub(EntityManagerInterface::class),
        );

        return new ConfigReadinessProvider(
            $env['appEnv'] ?? 'prod',
            $env['appSecret'] ?? str_repeat('a', 32),
            $env['defaultUri'] ?? 'https://tallyst.org',
            $env['encKey'] ?? base64_encode(str_repeat('k', 32)),
            $env['mailerDsn'] ?? 'smtp://user:pass@host:587',
            $env['orderEnv'] ?? '',
            $sm,
            $translator,
            $mailer,
        );
    }

    /**
     * @return array<string, Check>
     */
    private function byLabel(ConfigReadinessProvider $p): array
    {
        $out = [];
        foreach ($p->getChecks() as $c) {
            $out[$c->label] = $c;
        }

        return $out;
    }

    public function testHealthyConfigIsAllGreen(): void
    {
        $checks = $this->byLabel($this->provider(
            settings: ['smtp_host' => 'smtp.example.com', 'mail_from_email' => 'no-reply@tallyst.org', 'order_admin_email' => 'admin@real.hr'],
        ));

        foreach (['APP_SECRET', 'SETTINGS_ENCRYPTION_KEY', 'HTTPS', 'APP_ENV', 'DEFAULT_URI', 'admin.readiness.mailer.label', 'admin.readiness.mail_from.label', 'admin.readiness.order_email.label'] as $label) {
            self::assertSame(Status::OK, $checks[$label]->status, $label.' should be OK');
        }
    }

    public function testAppEnvDevIsWarningNotProblem(): void
    {
        $checks = $this->byLabel($this->provider(env: ['appEnv' => 'dev']));
        self::assertSame(Status::WARNING, $checks['APP_ENV']->status);
        self::assertStringContainsString('app_env.detail.dev', $checks['APP_ENV']->detail);
    }

    public function testEmptyAppSecretIsProblem(): void
    {
        $checks = $this->byLabel($this->provider(env: ['appSecret' => '']));
        self::assertSame(Status::PROBLEM, $checks['APP_SECRET']->status);
    }

    public function testBadEncryptionKeyIsProblem(): void
    {
        self::assertSame(Status::PROBLEM, $this->byLabel($this->provider(env: ['encKey' => '']))['SETTINGS_ENCRYPTION_KEY']->status);
        self::assertSame(Status::PROBLEM, $this->byLabel($this->provider(env: ['encKey' => base64_encode('tooshort')]))['SETTINGS_ENCRYPTION_KEY']->status);
    }

    public function testDefaultUriLocalhostIsWarningAndHttpsTracksScheme(): void
    {
        $local = $this->byLabel($this->provider(env: ['defaultUri' => 'http://localhost']));
        self::assertSame(Status::WARNING, $local['DEFAULT_URI']->status);
        self::assertSame(Status::WARNING, $local['HTTPS']->status, 'http scheme → HTTPS warning');

        $prod = $this->byLabel($this->provider(env: ['defaultUri' => 'https://tallyst.org']));
        self::assertSame(Status::OK, $prod['DEFAULT_URI']->status);
        self::assertSame(Status::OK, $prod['HTTPS']->status);
    }

    public function testMailerUnconfiguredIsWarning(): void
    {
        // smtp_host empty AND MAILER_DSN is the null placeholder.
        $checks = $this->byLabel($this->provider(env: ['mailerDsn' => 'null://null']));
        self::assertSame(Status::WARNING, $checks['admin.readiness.mailer.label']->status);
    }

    public function testOrderAdminPlaceholderIsWarning(): void
    {
        $checks = $this->byLabel($this->provider(env: ['orderEnv' => 'admin@tallyst.local']));
        self::assertSame(Status::WARNING, $checks['admin.readiness.order_email.label']->status);
    }

    public function testUndecryptableSmtpPasswordIsProblem(): void
    {
        $checks = $this->byLabel($this->provider(settings: ['smtp_host' => 'smtp.example.com'], smtpReadable: false));
        self::assertArrayHasKey('admin.readiness.mail_secret.label', $checks);
        self::assertSame(Status::PROBLEM, $checks['admin.readiness.mail_secret.label']->status);
    }

    public function testSmtpDecryptCheckIsSkippedWhenEverythingIsReadable(): void
    {
        self::assertArrayNotHasKey('admin.readiness.mail_secret.label', $this->byLabel($this->provider()));
    }

    public function testMailerReportsTheActiveProviderNotHardcodedSmtp(): void
    {
        // ⚠ Part B: an admin on Resend must see an accurate OK, not an incorrect "SMTP not
        // configured" warning — the whole reason this check was generalised.
        $checks = $this->byLabel($this->provider(
            settings: ['mail_provider' => 'resend', 'resend_api_key' => 're_live_abc'],
        ));

        self::assertSame(Status::OK, $checks['admin.readiness.mailer.label']->status);
        self::assertStringContainsString('Resend', $checks['admin.readiness.mailer.label']->detail);
    }

    public function testMailerWarnsWhenActiveProviderSelectedButNotConfigured(): void
    {
        $checks = $this->byLabel($this->provider(
            env: ['mailerDsn' => 'null://null'],
            settings: ['mail_provider' => 'mailgun', 'mailgun_api_key' => '', 'mailgun_domain' => ''],
        ));

        self::assertSame(Status::WARNING, $checks['admin.readiness.mailer.label']->status);
        self::assertStringContainsString('Mailgun', $checks['admin.readiness.mailer.label']->detail);
    }

    public function testUndecryptableSecretIsProblemForANonSmtpProviderToo(): void
    {
        $checks = $this->byLabel($this->provider(
            settings: ['mail_provider' => 'postmark', 'postmark_server_token' => 'stale-ciphertext'],
            smtpReadable: false,
        ));

        self::assertSame(Status::PROBLEM, $checks['admin.readiness.mail_secret.label']->status);
        self::assertStringContainsString('Postmark', $checks['admin.readiness.mail_secret.label']->detail);
    }
}
