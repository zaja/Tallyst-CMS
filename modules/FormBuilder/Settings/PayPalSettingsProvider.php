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
        yield new SettingsSection('paypal', 'PayPal', 'fa-brands fa-paypal', [
            new SettingDefinition('paypal_client_id', SettingType::STRING, 'Client ID', 'Iz PayPal app (sandbox ili live). Prazno = PAYPAL_CLIENT_ID iz okoline.', null),
            new SettingDefinition('paypal_client_secret', SettingType::PASSWORD, 'Client secret', 'Tajni ključ PayPal appa. Prazno = PAYPAL_CLIENT_SECRET iz okoline.', null, [], true),
            new SettingDefinition('paypal_webhook_id', SettingType::STRING, 'Webhook ID', 'ID webhooka iz PayPal dashboarda (treba za verifikaciju). Prazno = PAYPAL_WEBHOOK_ID iz okoline.', null),
            new SettingDefinition('paypal_mode', SettingType::CHOICE, 'Način rada', 'PayPal nema test/live prefiks u ključu — odaberi izričito. Sandbox i live imaju ODVOJENE ključeve i webhookove.', 'sandbox', [
                'Sandbox (test)' => 'sandbox',
                'Live (produkcija)' => 'live',
            ]),
        ]);
    }
}
