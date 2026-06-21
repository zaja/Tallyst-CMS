<?php

namespace App\Settings;

use App\Repository\SettingRepository;

/**
 * The typed read/write layer over the untyped Setting key/value store. Reads cast the raw
 * string back to the definition's PHP type and decrypt encrypted values; writes cast to a
 * string and encrypt encrypted values.
 *
 * WRITE-ONLY SECRETS: for an encrypted setting, an empty incoming value is a no-op — it
 * KEEPS the stored value. That is what lets the UI render the password field empty and only
 * overwrite when the admin actually types a new one ("•••• nepromijenjeno").
 */
class SettingsManager
{
    public function __construct(
        private readonly SettingRepository $settings,
        private readonly SettingsRegistry $registry,
        private readonly SettingsEncryptor $encryptor,
    ) {
    }

    /**
     * The typed, decrypted value for a key (or its schema default when unset).
     */
    public function get(string $key): mixed
    {
        $def = $this->registry->getDefinition($key);
        $raw = $this->settings->get($key);

        if (null === $raw || '' === $raw) {
            return $def?->default;
        }

        if ($def?->encrypted) {
            try {
                $raw = $this->encryptor->decrypt($raw);
            } catch (\RuntimeException) {
                // A lost/rotated/corrupt key must never 500 the app: treat an undecryptable
                // secret as unset (the schema default). Callers that need to react to the
                // failure use isEncryptedValueReadable().
                return $def->default;
            }
        }

        return $this->cast($raw, $def?->type);
    }

    /**
     * Whether an encrypted setting can actually be decrypted with the current key. True when
     * there is nothing encrypted stored (trivially fine); false only when a ciphertext IS
     * stored but the key can't decrypt it (lost/rotated/corrupt). Lets the mailer fall back
     * to env and the UI warn, instead of silently sending without auth or crashing.
     */
    public function isEncryptedValueReadable(string $key): bool
    {
        $def = $this->registry->getDefinition($key);
        $raw = $this->settings->get($key);

        if (!$def?->encrypted || null === $raw || '' === $raw) {
            return true;
        }

        try {
            $this->encryptor->decrypt($raw);

            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * The value to PREFILL a form field with. Secrets are never returned — the field stays
     * empty and a non-empty submit is required to change them.
     */
    public function getForForm(SettingDefinition $def): mixed
    {
        if ($def->type->isSecret()) {
            return null;
        }

        return $this->get($def->key);
    }

    /**
     * Persist one value. Encrypted secrets are encrypted; an empty secret is a no-op (keeps
     * the existing value — see class docblock). Booleans store '1'/'0', ints store digits.
     */
    public function set(string $key, mixed $value): void
    {
        $def = $this->registry->getDefinition($key);

        if ($def?->encrypted) {
            if (null === $value || '' === $value) {
                return; // write-only: empty means "leave unchanged"
            }
            $this->settings->set($key, $this->encryptor->encrypt((string) $value));

            return;
        }

        $this->settings->set($key, $this->normalize($value, $def?->type));
    }

    /**
     * @param array<string, mixed> $values key => value
     */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }
    }

    private function cast(string $raw, ?SettingType $type): mixed
    {
        return match ($type) {
            SettingType::BOOL => '1' === $raw,
            SettingType::INT => (int) $raw,
            default => $raw,
        };
    }

    private function normalize(mixed $value, ?SettingType $type): ?string
    {
        return match ($type) {
            SettingType::BOOL => $value ? '1' : '0',
            SettingType::INT => null === $value || '' === $value ? null : (string) (int) $value,
            default => null === $value ? null : (string) $value,
        };
    }
}
