<?php

namespace Tallyst\FormBuilder\Settings;

use App\Settings\SettingDefinition;
use App\Settings\SettingsSection;
use App\Settings\SettingsSectionProviderInterface;
use App\Settings\SettingType;

/**
 * FormBuilder contributes the "PayPal" settings section (parallel to Stripe — each provider owns its
 * own section). The secret is encrypted (like SMTP); all keys fall back to env in PayPalProcessor.
 * Mode is EXPLICIT (sandbox/live) because PayPal credentials carry no test/live marker.
 */
class PayPalSettingsProvider implements SettingsSectionProviderInterface
{
    public function getSettingsSections(): iterable
    {
        yield new SettingsSection('paypal', 'admin.settings.paypal.title', 'fa-brands fa-paypal', [
            new SettingDefinition('paypal_client_id', SettingType::STRING, 'admin.settings.paypal.paypal_client_id.label', 'admin.settings.paypal.paypal_client_id.help', null),
            new SettingDefinition('paypal_client_secret', SettingType::PASSWORD, 'admin.settings.paypal.paypal_client_secret.label', 'admin.settings.paypal.paypal_client_secret.help', null, [], true),
            new SettingDefinition('paypal_webhook_id', SettingType::STRING, 'admin.settings.paypal.paypal_webhook_id.label', 'admin.settings.paypal.paypal_webhook_id.help', null),
            new SettingDefinition('paypal_mode', SettingType::CHOICE, 'admin.settings.paypal.paypal_mode.label', 'admin.settings.paypal.paypal_mode.help', 'sandbox', [
                'admin.settings.paypal.paypal_mode.choice.sandbox' => 'sandbox',
                'admin.settings.paypal.paypal_mode.choice.live' => 'live',
            ]),
        ]);
    }
}
