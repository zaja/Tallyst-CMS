<?php

namespace App\Settings;

/**
 * The type of a setting — drives form widget selection (in SettingsController) and
 * value casting (in SettingsManager). A typed layer over the untyped Setting key/value
 * store, so the raw string in the DB is always cast back to the right PHP type.
 */
enum SettingType: string
{
    case STRING = 'string';
    case TEXT = 'text';
    case BOOL = 'bool';
    case INT = 'int';
    case CHOICE = 'choice';
    case EMAIL = 'email';
    case PASSWORD = 'password';
    /** A Media reference stored as its id (string); edited with the media-library picker. */
    case MEDIA = 'media';
    /** Rich content (HTML + shortcodes) edited with Tiptap, rendered via render_content. */
    case RICH_TEXT = 'rich_text';

    /** A secret value: write-only in the UI, never rendered back. */
    public function isSecret(): bool
    {
        return self::PASSWORD === $this;
    }
}
