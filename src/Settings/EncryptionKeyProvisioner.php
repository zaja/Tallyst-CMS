<?php

namespace App\Settings;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Ensures a SETTINGS_ENCRYPTION_KEY exists in .env.local (the git-ignored secrets file).
 * Called by app:install so a fresh deploy gets a real encryption key without a manual step.
 * Idempotent: it NEVER overwrites an existing non-empty key — only fills a missing one.
 */
class EncryptionKeyProvisioner
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return string|null the newly generated key, or null if one was already present
     */
    public function ensure(): ?string
    {
        $file = $this->projectDir.'/.env.local';
        $contents = is_file($file) ? (string) file_get_contents($file) : '';

        // A non-empty SETTINGS_ENCRYPTION_KEY line already present -> leave it untouched.
        if (preg_match('/^SETTINGS_ENCRYPTION_KEY=\S/m', $contents)) {
            return null;
        }

        $key = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $block = "\n###> tallyst/settings ###\nSETTINGS_ENCRYPTION_KEY=".$key."\n###< tallyst/settings ###\n";
        file_put_contents($file, $contents.$block, \LOCK_EX);

        return $key;
    }
}
