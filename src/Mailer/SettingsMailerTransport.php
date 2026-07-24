<?php

namespace App\Mailer;

use App\Entity\Setting;
use App\Settings\SettingsManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Decorates the mailer transport (`mailer.transports`) so ALL outgoing mail — synchronous
 * sends AND async-queued mail (FormBuilder order/payment confirmations go through Messenger)
 * — uses the provider configured in the admin Settings (SMTP, or a bridge-based provider like
 * Resend), with the env MAILER_DSN as a fallback. Both the Mailer and the Messenger
 * MessageHandler resolve the transport through this one service, so it is the single source
 * of truth for "how mail is sent" (no split-brain where the test button uses one provider but
 * real confirmations use another).
 *
 * Provider choice: `mail_provider` (default 'smtp' — an untouched installation behaves
 * BIT-IDENTICALLY to before this existed). 'smtp' keeps its own branch (resolveSmtpTransport,
 * unchanged): the password is decrypted ONLY in memory and set via setPassword() — never
 * placed in a DSN string, so it cannot leak into logs or the profiler. Every other provider is
 * DATA-DRIVEN via MailProviderRegistry: resolveProviderTransport() builds a DSN from the
 * provider's template + stored fields and hands it to Symfony's own $transportFactory (the
 * `mailer.transport_factory` service every installed mailer bridge tags itself into) — adding a
 * 5th/6th provider is one MailProviderRegistry entry, never a new branch here.
 *
 * Freshness: resolveTransport() explicitly clears the Setting identity map before reading
 * anything, so BOTH a short-lived web request and a long-running Messenger worker always see
 * the current provider/credentials — correctness doesn't depend on Doctrine's ambient
 * per-message reset behaviour (which a future Symfony/Doctrine/worker-flag change could alter).
 */
class SettingsMailerTransport implements TransportInterface
{
    public function __construct(
        private readonly TransportInterface $inner,
        private readonly SettingsManager $settings,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly MailProviderRegistry $providers,
        #[Autowire(service: 'mailer.transport_factory')]
        private readonly Transport $transportFactory,
        private readonly EntityManagerInterface $em,
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
     * result and the settings-page status line, so there's no guessing where a message went.
     */
    public function activeTransportLabel(): string
    {
        $provider = $this->activeProviderKey();

        if ('smtp' === $provider) {
            return $this->isDbSmtpActive()
                ? \sprintf('DB SMTP (%s)', (string) $this->settings->get('smtp_host'))
                : 'env MAILER_DSN (fallback)';
        }

        $definition = $this->providers->get($provider);
        if (null === $definition) {
            return 'env MAILER_DSN (fallback)';
        }

        return $this->isProviderConfigured($definition)
            ? $definition->label
            : \sprintf('%s (not configured — env MAILER_DSN fallback)', $definition->label);
    }

    /**
     * Whether the CURRENTLY selected provider is fully configured — the EXACT same criterion
     * resolveTransport() uses to decide "build the real transport" vs. "fall back to env".
     * Exposed (alongside activeProviderLabel()) for the readiness panel, which must report on
     * whichever provider is active instead of hardcoding SMTP — reusing this class's own branch
     * logic is deliberate: SMTP's real requirement (host set + password decryptable, username
     * optional) does NOT match MailProviderDefinition's generic "every field non-empty" rule, so
     * this must stay the ONE place that knows the difference, not re-derived elsewhere.
     */
    public function isActiveProviderConfigured(): bool
    {
        $provider = $this->activeProviderKey();

        if ('smtp' === $provider) {
            return $this->isDbSmtpActive();
        }

        $definition = $this->providers->get($provider);

        return null !== $definition && $this->isProviderConfigured($definition);
    }

    /**
     * The active provider's plain display name (e.g. "SMTP", "Resend") — no "(not
     * configured…)" suffix (that compound string is specific to activeTransportLabel(), used
     * in the test-mail flash).
     */
    public function activeProviderLabel(): string
    {
        $provider = $this->activeProviderKey();
        if ('smtp' === $provider) {
            return 'SMTP';
        }

        return $this->providers->get($provider)?->label ?? $provider;
    }

    /**
     * Whether the active provider has a STORED-but-undecryptable secret (a lost/rotated
     * encryption key) — worse than merely "unconfigured", worth its own PROBLEM-level report.
     * Fully generic: 'smtp' is a MailProviderRegistry entry too (see the class docblock), so its
     * field list comes from the same place as every other provider's, with no special-casing.
     * isEncryptedValueReadable() is a no-op-true for a non-encrypted or never-set field, so
     * checking every field of the active provider (not just its one secret) is harmless.
     */
    public function activeProviderSecretUndecryptable(): bool
    {
        $fields = $this->providers->get($this->activeProviderKey())?->fields ?? [];

        foreach ($fields as $field) {
            if (!$this->settings->isEncryptedValueReadable($field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * DB SMTP is usable only when a host is set AND the encrypted password can be decrypted.
     * A set-but-undecryptable password (lost/rotated key) makes the whole DB SMTP "incomplete"
     * so we fall back to env rather than send unauthenticated — see resolveSmtpTransport().
     */
    private function isDbSmtpActive(): bool
    {
        if ('' === (string) $this->settings->get('smtp_host')) {
            return false;
        }

        return $this->settings->isEncryptedValueReadable('smtp_password');
    }

    private function activeProviderKey(): string
    {
        return (string) ($this->settings->get('mail_provider') ?: 'smtp');
    }

    /**
     * Build the active provider's transport, or fall back to the env transport. Protected so
     * the branch logic is unit-testable without opening a socket/making an HTTP call.
     */
    protected function resolveTransport(): TransportInterface
    {
        // Force freshness in BOTH a short-lived web request and a long-running Messenger
        // worker: without this, a worker's cached Doctrine identity map could keep serving an
        // already-changed mail_provider/credential for the rest of its process life. Scoped to
        // Setting only, so it never disturbs an in-flight flush of unrelated entities.
        $this->em->clear(Setting::class);

        $provider = $this->activeProviderKey();

        return 'smtp' === $provider
            ? $this->resolveSmtpTransport()
            : $this->resolveProviderTransport($provider);
    }

    /**
     * The original SMTP branch, unchanged: the password is decrypted ONLY in memory and set
     * via setPassword() — never placed in a DSN string, so it cannot leak into logs or the
     * profiler.
     */
    private function resolveSmtpTransport(): TransportInterface
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

    /**
     * Any non-SMTP provider: build a DSN from its MailProviderRegistry definition + the
     * currently stored field values, and hand it to $transportFactory (the same
     * `mailer.transport_factory` service every installed Symfony mailer bridge tags itself
     * into) — Symfony's own bridge code does the actual HTTP-transport construction, we never
     * write that integration ourselves.
     */
    private function resolveProviderTransport(string $providerKey): TransportInterface
    {
        $definition = $this->providers->get($providerKey);
        if (null === $definition) {
            $this->logger?->warning('Unknown mail provider selected; falling back to env MAILER_DSN.', ['provider' => $providerKey]);

            return $this->inner;
        }

        if (!$this->isProviderConfigured($definition)) {
            $this->logger?->warning('Mail provider selected but not fully configured; falling back to env MAILER_DSN.', ['provider' => $providerKey]);

            return $this->inner;
        }

        try {
            return $this->transportFactory->fromString($definition->buildDsn($this->providerValues($definition)));
        } catch (\Throwable $e) {
            // Never log the DSN or a field value here — only the provider key and the
            // library's own exception message. Verified against Symfony's source: neither
            // Dsn::fromString()'s InvalidArgumentException nor UnsupportedSchemeException embeds
            // the raw DSN/credentials (scheme/host only), the Dsn password property and every
            // bridge transport's own API-key constructor param carry #[\SensitiveParameter],
            // and e.g. ResendApiTransport::__toString() returns only the API host, never the key.
            $this->logger?->warning('Mail provider transport could not be built; falling back to env MAILER_DSN.', [
                'provider' => $providerKey,
                'error' => $e->getMessage(),
            ]);

            return $this->inner;
        }
    }

    private function isProviderConfigured(MailProviderDefinition $definition): bool
    {
        return $definition->isConfigured($this->providerValues($definition));
    }

    /**
     * @return array<string, mixed>
     */
    private function providerValues(MailProviderDefinition $definition): array
    {
        $values = [];
        foreach ($definition->fields as $field) {
            $values[$field] = $this->settings->get($field);
        }

        return $values;
    }
}
