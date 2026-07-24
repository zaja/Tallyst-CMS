<?php

namespace App\Tests\Mailer;

use App\Entity\Setting;
use App\Mailer\MailProviderRegistry;
use App\Mailer\SettingsMailerTransport;
use App\Settings\SettingsManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoApiTransport;
use Symfony\Component\Mailer\Bridge\Mailgun\Transport\MailgunApiTransport;
use Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkApiTransport;
use Symfony\Component\Mailer\Bridge\Resend\Transport\ResendApiTransport;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Exercises the build-vs-fallback branch of the transport decorator without opening a
 * socket/making an HTTP call, via a subclass that exposes the protected resolveTransport().
 * KOMAD 1 shipped smtp + resend; KOMAD 2 added mailgun/postmark/brevo — testProviderBuilds()
 * below is the proof none of them needed a new branch here, only a MailProviderRegistry entry.
 */
class SettingsMailerTransportTest extends TestCase
{
    /**
     * @param array<string, mixed> $settings
     */
    private function transport(
        array $settings,
        TransportInterface $inner,
        bool $passwordReadable = true,
        ?EntityManagerInterface $em = null,
    ): SettingsMailerTransport {
        $manager = $this->createStub(SettingsManager::class);
        $manager->method('get')->willReturnCallback(fn (string $key) => $settings[$key] ?? null);
        $manager->method('isEncryptedValueReadable')->willReturn($passwordReadable);

        $em ??= $this->createStub(EntityManagerInterface::class);
        // A REAL Transport, built exactly like the framework's own `mailer.transport_factory`
        // service (Transport::getDefaultFactories()) — proves the DSN this class builds
        // actually parses through Symfony's own bridge code, not a mock standing in for it.
        $transportFactory = new Transport(iterator_to_array(Transport::getDefaultFactories()));

        return new class($inner, $manager, new EventDispatcher(), new MailProviderRegistry(), $transportFactory, $em) extends SettingsMailerTransport {
            public function resolvePublic(): TransportInterface
            {
                return $this->resolveTransport();
            }
        };
    }

    public function testFallsBackToEnvTransportWhenNoSmtpHost(): void
    {
        $inner = $this->createStub(TransportInterface::class);
        $t = $this->transport(['smtp_host' => ''], $inner);

        self::assertSame($inner, $t->resolvePublic(), 'no host => env MAILER_DSN fallback');
    }

    public function testBuildsSmtpTransportFromSettings(): void
    {
        $inner = $this->createStub(TransportInterface::class);
        $t = $this->transport([
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'smtp_username' => 'user',
            'smtp_password' => 'decrypted-pass',
        ], $inner);

        $built = $t->resolvePublic();

        self::assertInstanceOf(EsmtpTransport::class, $built);
        self::assertSame('smtp.example.test', $built->getStream()->getHost());
        self::assertSame(465, $built->getStream()->getPort());
        self::assertNotSame($inner, $built);
    }

    public function testFallsBackToEnvWhenPasswordCannotBeDecrypted(): void
    {
        $inner = $this->createStub(TransportInterface::class);
        // Host is set, but the stored password can't be decrypted (lost/rotated key).
        $t = $this->transport(['smtp_host' => 'smtp.example.test'], $inner, passwordReadable: false);

        self::assertSame($inner, $t->resolvePublic(), 'undecryptable password => env fallback, not unauthenticated send');
        self::assertStringContainsString('fallback', $t->activeTransportLabel());
    }

    public function testActiveTransportLabelReportsDbSmtpWhenConfigured(): void
    {
        $t = $this->transport(['smtp_host' => 'smtp.example.test'], $this->createStub(TransportInterface::class));

        self::assertStringContainsString('DB SMTP', $t->activeTransportLabel());
        self::assertStringContainsString('smtp.example.test', $t->activeTransportLabel());
    }

    public function testActiveTransportLabelReportsEnvWhenNoHost(): void
    {
        $t = $this->transport(['smtp_host' => ''], $this->createStub(TransportInterface::class));

        self::assertStringContainsString('fallback', $t->activeTransportLabel());
    }

    public function testUnsetMailProviderDefaultsToSmtpBitIdentical(): void
    {
        // ⚠ REGRESSION GUARD: an installation that never touched mail_provider (the key is
        // simply absent, exactly like an untouched Setting row) must behave EXACTLY like before
        // this feature existed.
        $inner = $this->createStub(TransportInterface::class);
        $t = $this->transport([
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'smtp_username' => 'user',
            'smtp_password' => 'decrypted-pass',
            // no 'mail_provider' key at all
        ], $inner);

        self::assertInstanceOf(EsmtpTransport::class, $t->resolvePublic());
        self::assertStringContainsString('DB SMTP', $t->activeTransportLabel());
    }

    public function testBuildsResendTransportFromApiKey(): void
    {
        $inner = $this->createStub(TransportInterface::class);
        $t = $this->transport([
            'mail_provider' => 'resend',
            'resend_api_key' => 're_test_KEY123',
        ], $inner);

        $built = $t->resolvePublic();

        self::assertInstanceOf(ResendApiTransport::class, $built);
        self::assertNotSame($inner, $built);
        self::assertSame('Resend', $t->activeTransportLabel());
    }

    public function testResendFallsBackToEnvWhenApiKeyEmpty(): void
    {
        $inner = $this->createStub(TransportInterface::class);
        $t = $this->transport([
            'mail_provider' => 'resend',
            'resend_api_key' => '',
        ], $inner);

        self::assertSame($inner, $t->resolvePublic(), 'resend selected but no key => env fallback, not an unauthenticated send');
        self::assertStringContainsString('not configured', $t->activeTransportLabel());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: class-string, 2: string}>
     */
    public static function providerCases(): array
    {
        return [
            'resend' => [['mail_provider' => 'resend', 'resend_api_key' => 're_test_KEY123'], ResendApiTransport::class, 'Resend'],
            'mailgun' => [['mail_provider' => 'mailgun', 'mailgun_api_key' => 'key123', 'mailgun_domain' => 'mg.example.test', 'mailgun_region' => 'eu'], MailgunApiTransport::class, 'Mailgun'],
            'postmark' => [['mail_provider' => 'postmark', 'postmark_server_token' => 'srv_token_123'], PostmarkApiTransport::class, 'Postmark'],
            'brevo' => [['mail_provider' => 'brevo', 'brevo_api_key' => 'key_abc'], BrevoApiTransport::class, 'Brevo'],
        ];
    }

    /**
     * Every KOMAD 2 provider, built through the SAME generic branch used for Resend — no
     * per-provider code, only a MailProviderRegistry entry + these fixture values.
     *
     * @param array<string, mixed> $settings
     */
    #[DataProvider('providerCases')]
    public function testProviderBuilds(array $settings, string $expectedClass, string $expectedLabel): void
    {
        $inner = $this->createStub(TransportInterface::class);
        $t = $this->transport($settings, $inner);

        $built = $t->resolvePublic();

        self::assertInstanceOf($expectedClass, $built);
        self::assertNotSame($inner, $built);
        self::assertSame($expectedLabel, $t->activeTransportLabel());
    }

    public function testUnknownProviderFallsBackToEnv(): void
    {
        // Defensive: a corrupted/stale mail_provider value (e.g. a KOMAD 2 provider key on a
        // KOMAD 1-only install that was rolled back) must never crash or send unauthenticated.
        $inner = $this->createStub(TransportInterface::class);
        $t = $this->transport(['mail_provider' => 'does-not-exist'], $inner);

        self::assertSame($inner, $t->resolvePublic());
    }

    public function testResolveTransportClearsSettingIdentityMapEveryCall(): void
    {
        // Proves the freshness discipline: a long-running Messenger worker (or any caller that
        // resolves this more than once in its process life) must never see a stale Setting row
        // just because Doctrine's identity map still holds an old one.
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::exactly(2))->method('clear')->with(Setting::class);

        $t = $this->transport(['smtp_host' => ''], $this->createStub(TransportInterface::class), em: $em);
        $t->resolvePublic();
        $t->resolvePublic();
    }
}
