<?php

namespace Tallyst\FormBuilder\Settings;

use App\Settings\SettingDefinition;
use App\Settings\SettingsSection;
use App\Settings\SettingsSectionProviderInterface;
use App\Settings\SettingType;

/**
 * FormBuilder's "Porez" settings section. Deliberately small: ONE inclusive rate (toggle) — Tallyst
 * is not a tax engine or Merchant-of-Record. The help text states the honest boundary so the admin
 * doesn't mistake it for global compliance.
 */
class TaxSettingsProvider implements SettingsSectionProviderInterface
{
    public function getSettingsSections(): iterable
    {
        yield new SettingsSection('tax', 'admin.settings.tax.title', 'fa-percent', [
            new SettingDefinition('tax_enabled', SettingType::BOOL, 'admin.settings.tax.tax_enabled.label', 'admin.settings.tax.tax_enabled.help', false),
            new SettingDefinition('tax_rate', SettingType::STRING, 'admin.settings.tax.tax_rate.label', 'admin.settings.tax.tax_rate.help', '25'),
            new SettingDefinition('tax_name', SettingType::STRING, 'admin.settings.tax.tax_name.label', 'admin.settings.tax.tax_name.help', 'PDV'),
        ]);
    }
}
