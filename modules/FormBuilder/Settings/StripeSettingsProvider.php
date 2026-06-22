<?php

namespace Tallyst\FormBuilder\Settings;

use App\Settings\SettingDefinition;
use App\Settings\SettingsSection;
use App\Settings\SettingsSectionProviderInterface;
use App\Settings\SettingType;

/**
 * FormBuilder contributes the "Stripe" settings section (IoC — Stripe is the module's domain, so
 * Core's CoreSettingsProvider stays free of it). Keys are encrypted secrets (like the SMTP
 * password) and fall back to env in StripeProcessor when left empty. PUBLISHABLE is intentionally
 * absent (unused — hosted Checkout redirect).
 */
class StripeSettingsProvider implements SettingsSectionProviderInterface
{
    public function getSettingsSections(): iterable
    {
        yield new SettingsSection('stripe', 'Stripe', 'fa-credit-card', [
            new SettingDefinition('stripe_secret_key', SettingType::PASSWORD, 'Tajni ključ (Secret key)', 'sk_test_… ili sk_live_… Prazno = STRIPE_SECRET_KEY iz okoline.', null, [], true),
            new SettingDefinition('stripe_webhook_secret', SettingType::PASSWORD, 'Webhook secret', 'whsec_… s istog (test/live) endpointa. Prazno = STRIPE_WEBHOOK_SECRET iz okoline.', null, [], true),
            new SettingDefinition('checkout_locale', SettingType::CHOICE, 'Jezik naplate (checkout)', 'Jezik Stripe checkout stranice. Automatski = prati preglednik kupca.', 'auto', [
                'Automatski' => 'auto',
                'Hrvatski' => 'hr',
                'English' => 'en',
                'Deutsch' => 'de',
                'Italiano' => 'it',
                'Français' => 'fr',
                'Español' => 'es',
            ]),
        ]);
    }
}
