<?php

namespace Tallyst\FormBuilder\Settings;

use App\Settings\SettingDefinition;
use App\Settings\SettingsSection;
use App\Settings\SettingsSectionProviderInterface;
use App\Settings\SettingType;

/**
 * FormBuilder's "Narudžbe" settings section. Holds order-facing copy the admin can edit — currently the
 * thank-you message shown after a successful payment (above the dynamic order block in thank_you.html.twig).
 */
class OrderSettingsProvider implements SettingsSectionProviderInterface
{
    public function getSettingsSections(): iterable
    {
        yield new SettingsSection('orders', 'Narudžbe', 'fa-receipt', [
            new SettingDefinition('thank_you_message', SettingType::RICH_TEXT, 'Poruka zahvale', 'Prikazuje se kupcu na stranici zahvale nakon uspješnog plaćanja, iznad podataka o narudžbi.', '<p>Hvala na narudžbi! Potvrdu i upute šaljemo e-mailom.</p>'),
        ]);
    }
}
