<?php

namespace Tallyst\FormBuilder\Settings;

use App\Settings\SettingDefinition;
use App\Settings\SettingsSection;
use App\Settings\SettingsSectionProviderInterface;
use App\Settings\SettingType;

/**
 * FormBuilder contributes the "Dodo Payments" settings section (parallel to Stripe/PayPal — each
 * provider owns its own section). Secrets are encrypted (like SMTP / the Stripe key); all keys fall
 * back to env in DodoProcessor. Mode is EXPLICIT (test/live) because Dodo keys carry no marker.
 *
 * The section auto-appears as its own settings tab via the IoC fallback (v1.5.0 tab architecture) —
 * 'dodo' is not in SettingsRegistry::GROUPS, so it gets its own tab with no tab-code change.
 *
 * The Dodo PRODUCT is linked PER FORM (FormDefinition.dodoProductId), not here — a global product
 * would force every Dodo form to share one product. No product-id setting lives in this section.
 */
class DodoSettingsProvider implements SettingsSectionProviderInterface
{
    public function getSettingsSections(): iterable
    {
        yield new SettingsSection('dodo', 'admin.settings.dodo.title', 'fa-box', [
            new SettingDefinition('dodo_api_key', SettingType::PASSWORD, 'admin.settings.dodo.dodo_api_key.label', 'admin.settings.dodo.dodo_api_key.help', null, [], true),
            new SettingDefinition('dodo_webhook_secret', SettingType::PASSWORD, 'admin.settings.dodo.dodo_webhook_secret.label', 'admin.settings.dodo.dodo_webhook_secret.help', null, [], true),
            new SettingDefinition('dodo_mode', SettingType::CHOICE, 'admin.settings.dodo.dodo_mode.label', 'admin.settings.dodo.dodo_mode.help', 'test', [
                'admin.settings.dodo.dodo_mode.choice.test' => 'test',
                'admin.settings.dodo.dodo_mode.choice.live' => 'live',
            ]),
        ]);
    }
}
