<?php

namespace App\Content;

/**
 * A replacement tag handler. Modules implement this and are auto-registered with
 * the ShortcodeRegistry (tag "app.shortcode"). Core knows nothing about specific
 * tags — e.g. FormBuilder registers a handler whose getName() returns "form" so
 * that `[form id=5]` in page content renders a form.
 */
interface ShortcodeInterface
{
    /** The tag name, without brackets (e.g. "form"). */
    public function getName(): string;

    /**
     * @param array<string, string|bool> $attributes Parsed tag attributes
     * @param string|null                $content    Inner content for enclosing tags ([tag]...[/tag]), else null
     */
    public function render(array $attributes, ?string $content = null): string;
}
