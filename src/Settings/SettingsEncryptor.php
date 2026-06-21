<?php

namespace App\Settings;

/**
 * Encrypts secret settings (the SMTP password) at rest with libsodium's authenticated
 * secretbox (XSalsa20-Poly1305). The key comes from the SETTINGS_ENCRYPTION_KEY env var
 * (base64 of 32 raw bytes — injected already decoded via the `base64:` env processor).
 *
 * Stored form is base64(nonce . ciphertext): a fresh random nonce per encryption, so the
 * same plaintext never yields the same ciphertext. The key length is validated lazily (on
 * first use) so an environment that never touches encrypted settings can boot with an empty
 * placeholder key.
 */
class SettingsEncryptor
{
    public function __construct(private readonly string $key)
    {
    }

    public function encrypt(string $plaintext): string
    {
        $key = $this->key();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);

        return base64_encode($nonce.$cipher);
    }

    public function decrypt(string $stored): string
    {
        $key = $this->key();
        $decoded = base64_decode($stored, true);
        if (false === $decoded || \strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Encrypted setting is malformed.');
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        if (false === $plaintext) {
            throw new \RuntimeException('Could not decrypt setting (wrong key or tampered data).');
        }

        return $plaintext;
    }

    private function key(): string
    {
        if (SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($this->key)) {
            throw new \LogicException(
                'SETTINGS_ENCRYPTION_KEY must be base64 of exactly 32 bytes. '
                .'Generate one with: php8.5 -r \'echo base64_encode(random_bytes(32));\' and put it in .env.local.'
            );
        }

        return $this->key;
    }
}
