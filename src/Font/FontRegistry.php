<?php

namespace App\Font;

/**
 * Curated catalog of the SYSTEM fonts a site can pick (display + body), mirroring IconRegistry —
 * a Core catalog, theme-INDEPENDENT. Unlike icons (PHP-inline SVG text), fonts are binary woff2,
 * so the files live in `public/fonts/` (committed, served by nginx as plain static at an ABSOLUTE
 * `/fonts/<file>` URL — no AssetMapper, no theme publish). This catalog holds only the metadata;
 * FontExtension turns a chosen key into `@font-face` + a `:root` var override.
 *
 * `system` (the default) is the OS stack — NO `@font-face`, NO download; picking it leaves the site
 * exactly as before (additive). Curated + code-extensible ONLY (add a font = drop the woff2 in
 * public/fonts/ + one entry here + its licence); NO runtime upload (a separate, later feature). Each
 * curated font MUST cover Latin Extended (čćšđž) and carry an open licence — vetted at dev time.
 *
 * This is a DEMONSTRATION catalog (proves the mechanism SCALES over many fonts); the final curated
 * set + defaults + visual are Tema v2 (step 8). Licences in public/fonts/LICENSES/ (per font):
 * Inter, Manrope, Space Grotesk, IBM Plex Sans, Lora, Libre Baskerville — SIL OFL 1.1;
 * Roboto — Apache License 2.0 (NOT OFL — attributed accordingly).
 */
final class FontRegistry
{
    private const SYSTEM_STACK = 'system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif';
    private const SERIF_STACK = 'ui-serif, Georgia, "Times New Roman", serif';

    /**
     * key => [label, stack (full CSS font-family value), faces[] (file+weight for @font-face)].
     *
     * @var array<string, array{label: string, stack: string, faces: array<int, array{file: string, weight: int}>}>
     */
    public const FONTS = [
        'system' => [
            'label' => 'System',
            'stack' => self::SYSTEM_STACK,
            'faces' => [], // OS stack — no web font
        ],
        'inter' => [
            'label' => 'Inter',
            'stack' => "'Inter', ".self::SYSTEM_STACK,
            'faces' => [
                ['file' => 'inter-400.woff2', 'weight' => 400],
                ['file' => 'inter-500.woff2', 'weight' => 500],
                ['file' => 'inter-600.woff2', 'weight' => 600],
                ['file' => 'inter-700.woff2', 'weight' => 700],
            ],
        ],
        'roboto' => [
            'label' => 'Roboto',
            'stack' => "'Roboto', ".self::SYSTEM_STACK,
            'faces' => [
                ['file' => 'roboto-400.woff2', 'weight' => 400],
                ['file' => 'roboto-700.woff2', 'weight' => 700],
            ],
        ],
        'manrope' => [
            'label' => 'Manrope',
            'stack' => "'Manrope', ".self::SYSTEM_STACK,
            'faces' => [
                ['file' => 'manrope-400.woff2', 'weight' => 400],
                ['file' => 'manrope-700.woff2', 'weight' => 700],
            ],
        ],
        'space-grotesk' => [
            'label' => 'Space Grotesk',
            'stack' => "'Space Grotesk', ".self::SYSTEM_STACK,
            'faces' => [
                ['file' => 'space-grotesk-400.woff2', 'weight' => 400],
                ['file' => 'space-grotesk-700.woff2', 'weight' => 700],
            ],
        ],
        'ibm-plex-sans' => [
            'label' => 'IBM Plex Sans',
            'stack' => "'IBM Plex Sans', ".self::SYSTEM_STACK,
            'faces' => [
                ['file' => 'ibm-plex-sans-400.woff2', 'weight' => 400],
                ['file' => 'ibm-plex-sans-700.woff2', 'weight' => 700],
            ],
        ],
        // Serif fonts → serif fallback.
        'lora' => [
            'label' => 'Lora',
            'stack' => "'Lora', ".self::SERIF_STACK,
            'faces' => [
                ['file' => 'lora-400.woff2', 'weight' => 400],
                ['file' => 'lora-700.woff2', 'weight' => 700],
            ],
        ],
        'libre-baskerville' => [
            'label' => 'Libre Baskerville',
            'stack' => "'Libre Baskerville', ".self::SERIF_STACK,
            'faces' => [
                ['file' => 'libre-baskerville-400.woff2', 'weight' => 400],
                ['file' => 'libre-baskerville-700.woff2', 'weight' => 700],
            ],
        ],
    ];

    /** @return array{label: string, stack: string, faces: array<int, array{file: string, weight: int}>}|null */
    public function get(string $key): ?array
    {
        return self::FONTS[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset(self::FONTS[$key]);
    }

    /** The full CSS font-family value for a key (or the system stack for an unknown/empty key). */
    public function stack(string $key): string
    {
        return self::FONTS[$key]['stack'] ?? self::SYSTEM_STACK;
    }

    /** True when the key is a real web font (has @font-face) — i.e. NOT `system`/unknown. */
    public function isWebFont(string $key): bool
    {
        return [] !== (self::FONTS[$key]['faces'] ?? []);
    }

    /** @return string[] every catalog key. */
    public function keys(): array
    {
        return array_keys(self::FONTS);
    }

    /**
     * Choices for the settings CHOICE field: label => key (like menuChoices()).
     *
     * @return array<string, string>
     */
    public function choices(): array
    {
        $choices = [];
        foreach (self::FONTS as $key => $font) {
            $choices[$font['label']] = $key;
        }

        return $choices;
    }
}
