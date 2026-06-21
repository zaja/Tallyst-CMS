<?php

namespace App\Settings;

/**
 * Describes ONE setting: its key (the Setting store name), type, UI label/help, default
 * value, choices (for CHOICE), and whether it is encrypted at rest. This is the schema the
 * friendly form and SettingsManager are both built from — the single description of a
 * setting, declared by a SettingsSectionProviderInterface.
 */
final readonly class SettingDefinition
{
    /**
     * @param array<string, string> $choices key=stored value, value=label (CHOICE only)
     */
    public function __construct(
        public string $key,
        public SettingType $type,
        public string $label,
        public string $help = '',
        public mixed $default = null,
        public array $choices = [],
        public bool $encrypted = false,
    ) {
    }
}
