<?php

namespace App\Tests\Readiness;

use App\Mailer\MailProviderRegistry;
use App\Mailer\SettingsMailerTransport;
use App\Readiness\Check;
use App\Readiness\ConfigReadinessProvider;
use App\Readiness\Status;
use App\Settings\SettingDefinition;
use App\Settings\SettingsManager;
use App\Settings\SettingsRegistry;
use App\Settings\SettingsSection;
use App\Settings\SettingsSectionProviderInterface;
use App\Settings\SettingType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ConfigReadinessProviderTest extends TestCase
{
    private const ENC_LABEL = 'admin.readiness.encrypted_settings.label';

    /**
     * A registry carrying a representative slice of the real schema: two payment secrets, two mail
     * secrets, and one PLAIN setting — so a test can prove the check consults only the encrypted ones.
     */
    private function registry(): SettingsRegistry
    {
        $enc = static fn (string $key): SettingDefinition => new SettingDefinition(
            $key, SettingType::PASSWORD, "admin.settings.x.$key.label", '', null, [], true,
        );

        $provider = new class($enc) implements SettingsSectionProviderInterface {
            /** @param callable(string): SettingDefinition $enc */
            public function __construct(private $enc)
            {
            }

            public function getSettingsSections(): iterable
            {
                $enc = $this->enc;

                yield new SettingsSection('x', 'X', '', [
                    $enc('stripe_secret_key'),
                    $enc('paypal_client_secret'),
                    $enc('smtp_password'),
                    $enc('postmark_server_token'),
                    new SettingDefinition('site_name', SettingType::TEXT, 'admin.settings.x.site_name.label'),
                ]);
            }
        };

        return new SettingsRegistry([$provider]);
    }

    /**
     * @param array<string, string> $env
     * @param array<string, string> $settings
     * @param list<string>          $unreadable keys whose stored ciphertext the current key can't decrypt
     */
    private function provider(array $env = [], array $settings = [], array $unreadable = []): ConfigReadinessProvider
    {
        $sm = $this->createStub(SettingsManager::class);
        $sm->method('get')->willReturnCallback(static fn (string $k): string => $settings[$k] ?? '');
        // Per-key, not a blanket answer: a lost key makes EVERY secret unreadable at once, but the
        // check has to be able to name WHICH ones — so the stub must distinguish them.
        $sm->method('isEncryptedValueReadable')
            ->willReturnCallback(static fn (string $k): bool => !\in_array($k, $unreadable, true));

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
            $this->registry(),
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
        $checks = $this->byLabel($this->provider(unreadable: ['smtp_password']));
        self::assertArrayHasKey(self::ENC_LABEL, $checks);
        self::assertSame(Status::PROBLEM, $checks[self::ENC_LABEL]->status);
    }

    public function testDecryptCheckIsSkippedWhenEverythingIsReadable(): void
    {
        self::assertArrayNotHasKey(self::ENC_LABEL, $this->byLabel($this->provider()));
    }

    /**
     * The point of generalising the old mail-only check: a lost key takes the PAYMENT secrets with
     * it, and that used to be invisible here — the panel only ever looked at the active mail
     * provider's fields.
     */
    public function testUnreadablePaymentSecretIsProblemAndIsNamed(): void
    {
        $checks = $this->byLabel($this->provider(unreadable: ['stripe_secret_key']));

        self::assertSame(Status::PROBLEM, $checks[self::ENC_LABEL]->status);
        self::assertStringContainsString('stripe_secret_key.label', $checks[self::ENC_LABEL]->detail,
            'the check must NAME the affected setting — "an encrypted setting" tells an owner nothing');
    }

    public function testEveryUnreadableSecretIsNamedNotJustTheFirst(): void
    {
        $checks = $this->byLabel($this->provider(unreadable: ['stripe_secret_key', 'smtp_password']));

        self::assertStringContainsString('stripe_secret_key.label', $checks[self::ENC_LABEL]->detail);
        self::assertStringContainsString('smtp_password.label', $checks[self::ENC_LABEL]->detail);
    }

    /** A plain (non-encrypted) setting is never consulted, so it can never trigger this. */
    public function testPlainSettingsAreNotTreatedAsSecrets(): void
    {
        self::assertArrayNotHasKey(self::ENC_LABEL, $this->byLabel($this->provider(unreadable: ['site_name'])));
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
            settings: ['mail_provider' => 'postmark'],
            unreadable: ['postmark_server_token'],
        ));

        self::assertSame(Status::PROBLEM, $checks[self::ENC_LABEL]->status);
        self::assertStringContainsString('postmark_server_token.label', $checks[self::ENC_LABEL]->detail);
    }
}
