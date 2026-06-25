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
        yield new SettingsSection('stripe', 'admin.settings.stripe.title', 'fa-credit-card', [
            new SettingDefinition('stripe_secret_key', SettingType::PASSWORD, 'admin.settings.stripe.stripe_secret_key.label', 'admin.settings.stripe.stripe_secret_key.help', null, [], true),
            new SettingDefinition('stripe_webhook_secret', SettingType::PASSWORD, 'admin.settings.stripe.stripe_webhook_secret.label', 'admin.settings.stripe.stripe_webhook_secret.help', null, [], true),
            new SettingDefinition('checkout_locale', SettingType::CHOICE, 'admin.settings.stripe.checkout_locale.label', 'admin.settings.stripe.checkout_locale.help', 'auto', [
                // Only "Automatic" is translated; language names render in their OWN language.
                'admin.settings.stripe.checkout_locale.choice.auto' => 'auto',
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
