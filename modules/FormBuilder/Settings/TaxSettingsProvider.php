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
        yield new SettingsSection('tax', 'Porez', 'fa-percent', [
            new SettingDefinition('tax_enabled', SettingType::BOOL, 'Obračunaj porez', 'Primjenjuje JEDNU stopu (uključenu u cijenu) na sve narudžbe — ispravno za domaću prodaju / ispod EU OSS praga (€10.000). Za prodaju preko praga ili globalnu usklađenost konzultiraj knjigovođu ili Merchant-of-Record (Paddle/Lemon Squeezy). Tallyst bilježi podatke za tvoj export, ne podnosi poreznu prijavu.', false),
            new SettingDefinition('tax_rate', SettingType::STRING, 'Stopa (%)', 'Npr. 25. Porez je UKLJUČEN u cijenu (izračuna se unatrag) — naplaćeni iznos se ne mijenja.', '25'),
            new SettingDefinition('tax_name', SettingType::STRING, 'Naziv poreza', 'Npr. PDV.', 'PDV'),
        ]);
    }
}
