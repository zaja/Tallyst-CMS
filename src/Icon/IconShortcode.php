<?php

namespace App\Icon;

use App\Content\ShortcodeInterface;

/**
 * [icon name=github label="GitHub"] — inline a curated icon in page content (feature lists,
 * sales pages). Auto-registered via the app.shortcode tag; the first Core (non-module) shortcode.
 *
 * Renders through the ONE IconRenderer over the curated IconRegistry — same layer as the theme's
 * `icon()` Twig function and the (future) top-bar social icons. Hardening: `name` is an allowlisted
 * registry key (unknown → nothing, like a missing [image] id); `label` is escaped by the renderer.
 * No arbitrary markup reaches the page.
 */
class IconShortcode implements ShortcodeInterface
{
    public function __construct(private readonly IconRenderer $icons)
    {
    }

    public function getName(): string
    {
        return 'icon';
    }

    public function render(array $attributes, ?string $content = null): string
    {
        $name = isset($attributes['name']) ? (string) $attributes['name'] : '';
        if ('' === $name) {
            return '';
        }

        $label = isset($attributes['label']) ? (string) $attributes['label'] : null;

        return $this->icons->render($name, $label);
    }
}
