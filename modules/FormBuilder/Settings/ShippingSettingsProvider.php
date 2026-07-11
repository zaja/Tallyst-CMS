<?php

namespace Tallyst\FormBuilder\Settings;

use App\Settings\SettingsSection;
use App\Settings\SettingsSectionProviderInterface;

/**
 * The "Shipping" (Dostava) settings tab. Unlike Tax (a scalar rate), shipping is a LIST of named
 * {label, price} methods — which the scalar setting schema can't render — so this section carries NO
 * SettingDefinitions; it exists only to register the tab slot (an ungrouped section → its own tab).
 * The list itself is edited by a custom collection editor (ShippingCatalog + the _shipping_methods
 * partial, included by the Core settings template like _payment_info) and stored as one JSON setting.
 * See PLAN-FAZA-1-DOSTAVA.md §3.
 */
class ShippingSettingsProvider implements SettingsSectionProviderInterface
{
    public function getSettingsSections(): iterable
    {
        yield new SettingsSection('shipping', 'admin.settings.shipping.title', 'fa-truck', []);
    }
}
