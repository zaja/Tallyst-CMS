<?php

namespace App\Twig;

use App\Font\FontRegistry;
use App\Settings\SettingsManager;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `font_styles()` → an inline `<style>` block the theme puts in `<head>` AFTER its CSS: it emits the
 * `@font-face` rules for the CHOSEN fonts (from an ABSOLUTE `/fonts/…` URL — theme-independent, no
 * theme_asset, no AssetMapper) and overrides the `:root` `--font` / `--font-display` tokens from the
 * `body_font` / `display_font` settings. Only the chosen fonts are declared, so the browser
 * downloads only those. Both `system` (the default) → EMPTY output → the site stays on the OS stack.
 *
 * THEME CONTRACT: any theme that wants the admin's font choice to apply MUST call `font_styles()` in
 * its `<head>` after loading its stylesheet (same kind of contract as `theme_asset`/`render_menu`).
 */
class FontExtension extends AbstractExtension
{
    public function __construct(
        private readonly SettingsManager $settings,
        private readonly FontRegistry $fonts,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            // is_safe html: the output is built entirely from the trusted FontRegistry catalog +
            // known setting keys — never from free-form input.
            new TwigFunction('font_styles', $this->fontStyles(...), ['is_safe' => ['html']]),
        ];
    }

    public function fontStyles(): string
    {
        $bodyKey = (string) $this->settings->get('body_font');
        $displayKey = (string) $this->settings->get('display_font');

        // @font-face for each chosen web font, once (dedupe when display == body).
        $faces = '';
        foreach (array_unique(array_filter([$bodyKey, $displayKey], $this->fonts->isWebFont(...))) as $key) {
            foreach ($this->fonts->get($key)['faces'] as $face) {
                $faces .= \sprintf(
                    "@font-face{font-family:%s;src:url('/fonts/%s') format('woff2');font-weight:%d;font-style:normal;font-display:swap;}",
                    $this->familyName($key),
                    $face['file'],
                    $face['weight'],
                );
            }
        }

        // :root overrides — only for a non-system choice (system leaves theme.css's default).
        $vars = '';
        if ($this->fonts->isWebFont($bodyKey)) {
            $vars .= '--font:'.$this->fonts->stack($bodyKey).';';
        }
        if ($this->fonts->isWebFont($displayKey)) {
            $vars .= '--font-display:'.$this->fonts->stack($displayKey).';';
        }

        if ('' === $faces && '' === $vars) {
            return ''; // both system → nothing to emit (additive; site unchanged)
        }

        return '<style>'.$faces.($vars ? ':root{'.$vars.'}' : '').'</style>';
    }

    /** The quoted family name for @font-face (the first token of the stack, e.g. 'Inter'). */
    private function familyName(string $key): string
    {
        return trim(explode(',', $this->fonts->stack($key))[0]);
    }
}
