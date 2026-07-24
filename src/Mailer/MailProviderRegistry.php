<?php

namespace App\Mailer;

/**
 * THE curated list of mail providers, as DATA — the single place a provider is added.
 * Everything that varies per provider (the `mail_provider` CHOICE options, which Setting keys
 * belong to which provider for the settings-form reveal, and how to build that provider's
 * transport DSN) is derived from this one list. Adding a provider is adding ONE entry here;
 * SettingsMailerTransport::resolveTransport() and the settings-form wiring never change.
 *
 * `smtp` is listed too (dsnTemplate null — see MailProviderDefinition) purely so its field keys
 * participate in the same "which provider owns this Setting" lookup as every DSN-based
 * provider; its transport is built by its own unchanged branch, never through this registry's
 * DSN path.
 *
 * KOMAD 1 shipped smtp + resend; KOMAD 2 added mailgun/postmark/brevo as three more entries —
 * confirming the mechanism: no change to SettingsMailerTransport or the settings-form reveal
 * controller was needed to add them (each was verified against its INSTALLED bridge factory,
 * not from memory — see MailProviderDefinition's per-provider notes below).
 */
final class MailProviderRegistry
{
    /** @var MailProviderDefinition[] */
    private readonly array $providers;

    public function __construct()
    {
        $this->providers = [
            new MailProviderDefinition(
                key: 'smtp',
                label: 'SMTP',
                fields: ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption'],
            ),
            new MailProviderDefinition(
                key: 'resend',
                label: 'Resend',
                fields: ['resend_api_key'],
                dsnTemplate: 'resend+api://{resend_api_key}@default',
            ),
            // Verified against Symfony\Component\Mailer\Bridge\Mailgun\Transport\MailgunTransportFactory
            // (installed): 'mailgun+api' reads the DSN user as the API key, the DSN password as
            // the domain, and an explicit 'region' query option (us|eu, no default in the
            // library itself — Tallyst defaults the Setting to 'us').
            new MailProviderDefinition(
                key: 'mailgun',
                label: 'Mailgun',
                fields: ['mailgun_api_key', 'mailgun_domain', 'mailgun_region'],
                dsnTemplate: 'mailgun+api://{mailgun_api_key}:{mailgun_domain}@default?region={mailgun_region}',
            ),
            // Verified against Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkTransportFactory
            // (installed): 'postmark+api' reads the DSN user as the Server API token — one field.
            new MailProviderDefinition(
                key: 'postmark',
                label: 'Postmark',
                fields: ['postmark_server_token'],
                dsnTemplate: 'postmark+api://{postmark_server_token}@default',
            ),
            // Verified against Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoTransportFactory
            // (installed): 'brevo+api' reads the DSN user as the API key — one field.
            new MailProviderDefinition(
                key: 'brevo',
                label: 'Brevo',
                fields: ['brevo_api_key'],
                dsnTemplate: 'brevo+api://{brevo_api_key}@default',
            ),
        ];
    }

    /**
     * @return MailProviderDefinition[]
     */
    public function getAll(): array
    {
        return $this->providers;
    }

    public function get(string $key): ?MailProviderDefinition
    {
        foreach ($this->providers as $provider) {
            if ($provider->key === $key) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * The `mail_provider` CHOICE options: display label => stored value. Labels are literal
     * brand/protocol names, not translation keys — same convention as app_locale's language
     * names (a missing catalog entry passes the literal string through trans() unchanged, so
     * this needs no en/hr catalog additions).
     *
     * @return array<string, string>
     */
    public function choices(): array
    {
        $choices = [];
        foreach ($this->providers as $provider) {
            $choices[$provider->label] = $provider->key;
        }

        return $choices;
    }

    /**
     * Every Setting key owned by ANY provider, across the whole list — used to detect "did a
     * mail-delivery setting change" on save, regardless of which provider is currently active.
     *
     * @return string[]
     */
    public function allFieldKeys(): array
    {
        $keys = [];
        foreach ($this->providers as $provider) {
            $keys = [...$keys, ...$provider->fields];
        }

        return $keys;
    }

    /**
     * Which provider owns a given Setting key (for the settings-form reveal: tag each field
     * row with its owning provider so the JS controller can show only the active one). Null
     * for a key no provider owns (e.g. mail_from_email — a Sender field, not Delivery).
     */
    public function providerKeyForField(string $settingKey): ?string
    {
        foreach ($this->providers as $provider) {
            if (\in_array($settingKey, $provider->fields, true)) {
                return $provider->key;
            }
        }

        return null;
    }
}
