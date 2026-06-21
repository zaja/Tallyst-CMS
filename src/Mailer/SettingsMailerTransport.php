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
     * Build the SMTP transport from the DB settings, or fall back to the env transport when
     * SMTP is not configured (no host). Protected so the branch logic (build vs. fallback)
     * is unit-testable without opening a socket.
     */
    protected function resolveTransport(): TransportInterface
    {
        $host = (string) $this->settings->get('smtp_host');
        if ('' === $host) {
            return $this->inner;
        }

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
