<?php

namespace Tallyst\FormBuilder\Settings;

use App\Settings\SettingsSection;
use App\Settings\SettingsSectionProviderInterface;

/**
 * FormBuilder's "Porez" settings section. Like the Shipping tab, this section carries NO SettingDefinitions —
 * it exists only to register the tab slot (an ungrouped section → its own tab). Everything the Tax tab edits
 * is owned by FormBuilder and saved through ONE custom form (the _tax_rates partial + TaxSettingsController):
 * the `tax_enabled` MASTER switch (default off — a fresh install applies NO tax) AND the named rate LIST
 * (TaxCatalog, one JSON setting). Both persist on a single "Save" — the Core SettingsController never sees
 * either. The legacy tax_rate/tax_name scalars seed the catalog's default entry lazily (see TaxCatalog);
 * they are read only until the admin first saves the list. See PLAN-FAZA-3-POREZ.md §3, §6.
 */
class TaxSettingsProvider implements SettingsSectionProviderInterface
{
    public function getSettingsSections(): iterable
    {
        yield new SettingsSection('tax', 'admin.settings.tax.title', 'fa-percent', []);
    }
}
