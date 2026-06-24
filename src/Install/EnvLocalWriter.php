<?php

namespace App\Install;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Idempotent writer for the .env.local keys the installer manages
 * (DATABASE_URL, APP_SECRET, DEFAULT_URI, ORDER_ADMIN_EMAIL).
 *
 * Each key is UPSERTED in place — replace its value if a `^KEY=` line exists anywhere
 * (bare or inside a marker block), otherwise append `KEY="value"` at the end. Every other
 * line and marker block is preserved verbatim. Values are double-quoted and escaped per
 * Symfony Dotenv rules (the DSN contains `&`; passwords may contain `$ # space`). The file
 * is locked to 0600 after writing.
 *
 * Deliberately does NOT touch SETTINGS_ENCRYPTION_KEY — that stays with EncryptionKeyProvisioner
 * (its own block + idempotency + tests).
 */
class EnvLocalWriter
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * True when .env.local already has a non-empty value for $key (guards APP_SECRET so it
     * is generated once and never rotated, and DATABASE_URL for install detection).
     */
    public function hasNonEmpty(string $key): bool
    {
        return (bool) preg_match('/^'.preg_quote($key, '/').'=\S/m', $this->read());
    }

    /**
     * @param array<string, string> $pairs key => raw (unquoted) value
     */
    public function upsert(array $pairs): void
    {
        $contents = $this->read();

        foreach ($pairs as $key => $value) {
            $line = $key.'='.$this->quote($value);
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

            if (preg_match($pattern, $contents)) {
                // Callback replacement avoids preg_replace's `$`/backref interpretation in the DSN.
                $contents = preg_replace_callback($pattern, static fn (): string => $line, $contents, 1);
            } else {
                if ('' !== $contents && !str_ends_with($contents, "\n")) {
                    $contents .= "\n";
                }
                $contents .= $line."\n";
            }
        }

        $file = $this->path();
        file_put_contents($file, $contents, \LOCK_EX);
        @chmod($file, 0600);
    }

    private function path(): string
    {
        return $this->projectDir.'/.env.local';
    }

    private function read(): string
    {
        return is_file($this->path()) ? (string) file_get_contents($this->path()) : '';
    }

    /**
     * Double-quote and escape so Symfony Dotenv reads the value literally:
     * inside double quotes, `\`, `"` and `$` are the meaningful characters (`$` would
     * otherwise trigger variable interpolation).
     */
    private function quote(string $value): string
    {
        $escaped = str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value);

        return '"'.$escaped.'"';
    }
}
