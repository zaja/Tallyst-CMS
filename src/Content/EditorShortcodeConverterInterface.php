<?php

namespace App\Content;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Converts ONE shortcode family <-> its visual editor HTML at the Tiptap form boundary.
 * Mirrors the ShortcodeInterface/ShortcodeRegistry IoC pattern, applied to the editor:
 * Core defines the contract, each module implements it for its own tag (Media → [image],
 * FormBuilder → [form]), and EditorContentConverter aggregates them. The editor (Media)
 * therefore depends only on this Core contract — never on FormBuilder.
 *
 * Each implementation MUST touch only its own disjoint pattern (its shortcode in one
 * direction, its marked element in the other) and leave everything else verbatim, so the
 * aggregate result is independent of registration order (locked by a test).
 */
#[AutoconfigureTag('app.editor_shortcode_converter')]
interface EditorShortcodeConverterInterface
{
    /** Stored content -> editor HTML (shortcode becomes a visual node). */
    public function toEditorHtml(string $stored): string;

    /** Editor HTML -> stored content (visual node becomes the shortcode). */
    public function toStored(string $editorHtml): string;
}
