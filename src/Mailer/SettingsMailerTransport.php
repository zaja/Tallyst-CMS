<?php

namespace App\Mailer;

use App\Settings\SettingsManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Decorates the mailer transport (`mailer.transports`) so ALL outgoing mail — synchronous
 * sends AND async-queued mail (FormBuilder order/payment confirmations go through Messenger)
 * — uses the SMTP configured in the admin Settings, with the env MAILER_DSN as a fallback.
 * Both the Mailer and the Messenger MessageHandler resolve the transport through this one
 * service, so it is the single source of truth for "how mail is sent" (no split-brain where
 * the test button uses DB SMTP but real confirmations use env).
 *
 * Security: the SMTP password is decrypted ONLY in memory here, per send, and set on the
 * transport via setPassword() — it is never placed in a DSN string, so it cannot leak into
 * logs or the profiler. The transport is built fresh each send, so it always reflects the
 * current settings (a long-running Messenger worker caches the Setting row for its lifetime,
 * so changing SMTP settings requires a worker restart — acceptable at this volume).
 */
class SettingsMailerTransport implements TransportInterface
{
    public function __construct(
        private readonly TransportInterface $inner,
        private readonly SettingsManager $settings,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        return $this->resolveTransport()->send($message, $envelope);
    }

    public function __toString(): string
    {
        return 'settings://smtp';
    }

    /**
     * A human label for which transport is actually in use — surfaced in the "send test mail"
     * result so there's no guessing where a message went.
     */
    public function activeTransportLabel(): string
    {
        return $this->isDbSmtpActive()
            ? \sprintf('DB SMTP (%s)', (string) $this->settings->get('smtp_host'))
            : 'env MAILER_DSN (fallback)';
    }

    /**
     * DB SMTP is usable only when a host is set AND the encrypted password can be decrypted.
     * A set-but-undecryptable password (lost/rotated key) makes the whole DB SMTP "incomplete"
     * so we fall back to env rather than send unauthenticated — see resolveTransport().
     */
    private function isDbSmtpActive(): bool
    {
        if ('' === (string) $this->settings->get('smtp_host')) {
            return false;
        }

        return $this->settings->isEncryptedValueReadable('smtp_password');
    }

    /**
     * Build the SMTP transport from the DB settings, or fall back to the env transport when
     * SMTP is not configured (no host) or its password can't be decrypted. Protected so the
     * branch logic (build vs. fallback) is unit-testable without opening a socket.
     */
    protected function resolveTransport(): TransportInterface
    {
        if (!$this->isDbSmtpActive()) {
            if ('' !== (string) $this->settings->get('smtp_host')) {
                $this->logger?->warning('SMTP password could not be decrypted; falling back to env MAILER_DSN.');
            }

            return $this->inner;
        }

        $host = (string) $this->settings->get('smtp_host');
        $port = (int) ($this->settings->get('smtp_port') ?: 587);
        $encryption = (string) ($this->settings->get('smtp_encryption') ?: 'tls');
        // ssl => implicit TLS (465); tls => opportunistic STARTTLS (587); none => no TLS.
        $tls = match ($encryption) {
            'ssl' => true,
            'none' => false,
            default => null,
        };

        $transport = new EsmtpTransport($host, $port, $tls, $this->dispatcher, $this->logger);
        if ('none' === $encryption) {
            $transport->setAutoTls(false);
        }

        $username = (string) $this->settings->get('smtp_username');
        if ('' !== $username) {
            $transport->setUsername($username);
        }
        $password = $this->settings->get('smtp_password'); // decrypted in-memory
        if (null !== $password && '' !== $password) {
            $transport->setPassword((string) $password);
        }

        return $transport;
    }
}
