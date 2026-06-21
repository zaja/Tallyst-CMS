<?php

namespace App\Settings;

/**
 * A named group of settings, shown as one tab/section in the friendly form.
 */
final readonly class SettingsSection
{
    /**
     * @param SettingDefinition[] $definitions
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $icon,
        public array $definitions,
    ) {
    }
}
