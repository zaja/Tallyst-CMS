<?php

namespace App\Mailer;

/**
 * Describes ONE mail provider as DATA: its key (the stored `mail_provider` value), a display
 * label (a brand/protocol name — never translated, same convention as language names in
 * CoreSettingsProvider's app_locale choices), the Setting keys it owns, and — for a provider
 * built through Symfony's official mailer bridges — a DSN template with `{setting_key}`
 * placeholders.
 *
 * `dsnTemplate` is null for `smtp`: that provider is NOT built via a DSN string at all (the
 * password must never transit through one — see SettingsMailerTransport::resolveSmtpTransport()),
 * so it is handled by its own, unchanged branch. It is still listed here so its field keys
 * participate in the generic "which provider owns this Setting" lookup the settings form and
 * its reveal JS use.
 */
final readonly class MailProviderDefinition
{
    /**
     * @param string[] $fields Setting keys this provider owns, in display order. For a DSN
     *                         provider, every key here MUST appear as a `{key}` placeholder in
     *                         $dsnTemplate and is treated as required for isConfigured().
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $fields,
        public ?string $dsnTemplate = null,
    ) {
    }

    /**
     * True when every field this provider needs has a non-empty value. Used to decide whether
     * to build the provider's transport or fall back (same honesty as SMTP's isDbSmtpActive()):
     * an undecryptable secret already reads as empty via SettingsManager::get(), so a lost
     * encryption key naturally fails this check too, with no separate case needed.
     *
     * @param array<string, mixed> $values field key => current value (from SettingsManager)
     */
    public function isConfigured(array $values): bool
    {
        foreach ($this->fields as $field) {
            if ('' === (string) ($values[$field] ?? '')) {
                return false;
            }
        }

        return [] !== $this->fields;
    }

    /**
     * Builds the transport DSN from this provider's template + current field values. Every
     * value is rawurlencode()'d before substitution — a DSN is a URL, and an API key can
     * contain characters (/, @, :, %) that would otherwise corrupt parsing.
     *
     * @param array<string, mixed> $values field key => current value (from SettingsManager)
     */
    public function buildDsn(array $values): string
    {
        $replacements = [];
        foreach ($this->fields as $field) {
            $replacements['{'.$field.'}'] = rawurlencode((string) ($values[$field] ?? ''));
        }

        return strtr((string) $this->dsnTemplate, $replacements);
    }
}
