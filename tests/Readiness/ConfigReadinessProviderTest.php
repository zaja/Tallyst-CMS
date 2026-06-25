<?php

namespace App\Tests\Readiness;

use App\Readiness\Check;
use App\Readiness\ConfigReadinessProvider;
use App\Readiness\Status;
use App\Settings\SettingsManager;
use PHPUnit\Framework\TestCase;

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

        return new ConfigReadinessProvider(
            $env['appEnv'] ?? 'prod',
            $env['appSecret'] ?? str_repeat('a', 32),
            $env['defaultUri'] ?? 'https://tallyst.org',
            $env['encKey'] ?? base64_encode(str_repeat('k', 32)),
            $env['mailerDsn'] ?? 'smtp://user:pass@host:587',
            $env['orderEnv'] ?? '',
            $sm,
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

        foreach (['APP_SECRET', 'SETTINGS_ENCRYPTION_KEY', 'HTTPS', 'APP_ENV', 'DEFAULT_URI', 'Pošta (SMTP)', 'Adresa pošiljatelja', 'Email za narudžbe'] as $label) {
            self::assertSame(Status::OK, $checks[$label]->status, $label.' should be OK');
        }
    }

    public function testAppEnvDevIsWarningNotProblem(): void
    {
        $checks = $this->byLabel($this->provider(env: ['appEnv' => 'dev']));
        self::assertSame(Status::WARNING, $checks['APP_ENV']->status);
        self::assertStringContainsString('PRODUKCIJU', $checks['APP_ENV']->detail);
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
        self::assertSame(Status::WARNING, $checks['Pošta (SMTP)']->status);
    }

    public function testOrderAdminPlaceholderIsWarning(): void
    {
        $checks = $this->byLabel($this->provider(env: ['orderEnv' => 'admin@tallyst.local']));
        self::assertSame(Status::WARNING, $checks['Email za narudžbe']->status);
    }

    public function testUndecryptableSmtpPasswordIsProblem(): void
    {
        $checks = $this->byLabel($this->provider(settings: ['smtp_host' => 'smtp.example.com'], smtpReadable: false));
        self::assertArrayHasKey('SMTP lozinka', $checks);
        self::assertSame(Status::PROBLEM, $checks['SMTP lozinka']->status);
    }

    public function testSmtpDecryptCheckIsSkippedWhenNoDbSmtp(): void
    {
        // No smtp_host → the decrypt check should not even appear.
        self::assertArrayNotHasKey('SMTP lozinka', $this->byLabel($this->provider()));
    }
}
