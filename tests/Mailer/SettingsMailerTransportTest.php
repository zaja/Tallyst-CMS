<?php

namespace App\Tests\Mailer;

use App\Mailer\SettingsMailerTransport;
use App\Settings\SettingsManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Exercises the build-vs-fallback branch of the transport decorator without opening a
 * socket, via a subclass that exposes the protected resolveTransport().
 */
class SettingsMailerTransportTest extends TestCase
{
    /**
     * @param array<string, mixed> $settings
     */
    private function transport(array $settings, TransportInterface $inner, bool $passwordReadable = true): SettingsMailerTransport
    {
        $manager = $this->createStub(SettingsManager::class);
        $manager->method('get')->willReturnCallback(fn (string $key) => $settings[$key] ?? null);
        $manager->method('isEncryptedValueReadable')->willReturn($passwordReadable);

        return new class($inner, $manager, new EventDispatcher()) extends SettingsMailerTransport {
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
}
