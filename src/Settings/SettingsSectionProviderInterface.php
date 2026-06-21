<?php

namespace App\Settings;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Contributes one or more sections of settings to the registry. Core ships
 * CoreSettingsProvider; modules MAY add their own sections later via this same interface
 * (mechanism only — no module sections are built yet).
 *
 * Tagging lives on the interface (like ShortcodeInterface / EditorShortcodeConverterInterface)
 * so any implementation from Core or a module is auto-tagged without services.yaml wiring.
 */
#[AutoconfigureTag('app.settings_section')]
interface SettingsSectionProviderInterface
{
    /**
     * @return iterable<SettingsSection>
     */
    public function getSettingsSections(): iterable;
}
