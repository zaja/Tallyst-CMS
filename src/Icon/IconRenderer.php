<?php

namespace App\Icon;

/**
 * Renders a curated icon as inline SVG. The ONE renderer behind all three consumers (the
 * `icon()` Twig function, the `[icon]` shortcode, and the top-bar social icons).
 *
 * SECURITY (injection-safe): the SVG body is looked up by an ALLOWLISTED key in IconRegistry
 * and is fixed, trusted static markup — never a caller-supplied string. An unknown key renders
 * '' (nothing). The only caller-supplied value is the optional aria `label`, which is
 * HTML-escaped into an attribute. This is why the Twig function / shortcode may declare the
 * output `is_safe: html` — the source is the registry, not user input (same guarantee as the
 * [image]/display-heading allowlists).
 *
 * The SVG is emitted with `fill="currentColor"` and `1em` sizing so it inherits the surrounding
 * text's colour and size WITHOUT requiring theme CSS (foundational chrome must render sanely on
 * any theme). The `.tallyst-icon` class is the theme hook to REFINE the visual — Tema v2
 * (v1.4.0 step 8) owns scale/spacing/brand colour; here it's a minimal placeholder.
 */
final class IconRenderer
{
    public function __construct(private readonly IconRegistry $registry)
    {
    }

    /**
     * @param string      $key   an IconRegistry key (unknown → '')
     * @param string|null $label optional accessible name; when set the icon is a meaningful image
     *                           (role=img + aria-label), otherwise it's decorative (aria-hidden)
     */
    public function render(string $key, ?string $label = null): string
    {
        $icon = $this->registry->get($key);
        if (null === $icon) {
            return '';
        }

        [$viewBox, $body] = $icon;

        $a11y = null !== $label && '' !== $label
            ? \sprintf('role="img" aria-label="%s"', htmlspecialchars($label, \ENT_QUOTES))
            : 'aria-hidden="true" focusable="false"';

        return \sprintf(
            '<svg class="tallyst-icon" viewBox="%s" width="1em" height="1em" fill="currentColor" %s>%s</svg>',
            $viewBox,
            $a11y,
            $body,
        );
    }
}
