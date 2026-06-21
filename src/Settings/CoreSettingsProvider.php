<?php

namespace App\Settings;

/**
 * The Core settings sections (v1): General, Localization, Email. Modules may add their own
 * sections later via SettingsSectionProviderInterface; only Core sections exist for now.
 *
 * `site_name` lives HERE (General) as its single editable home — Branding owns only the
 * logo (visual identity). The front reads the same `site_name` Setting key regardless.
 */
class CoreSettingsProvider implements SettingsSectionProviderInterface
{
    public function getSettingsSections(): iterable
    {
        yield new SettingsSection('general', 'Općenito', 'fa-sliders', [
            new SettingDefinition('site_name', SettingType::STRING, 'Naziv sajta', 'Prikazuje se u zaglavlju i naslovu.', 'Tallyst'),
            new SettingDefinition('site_tagline', SettingType::STRING, 'Slogan', 'Kratak opis sajta (meta opis).', ''),
        ]);

        yield new SettingsSection('localization', 'Lokalizacija', 'fa-globe', [
            new SettingDefinition('app_locale', SettingType::CHOICE, 'Jezik', 'Zadani jezik sučelja i prijevoda.', 'en', [
                'English' => 'en',
                'Hrvatski' => 'hr',
            ]),
            new SettingDefinition('app_timezone', SettingType::CHOICE, 'Vremenska zona', 'Koristi se za prikaz datuma.', 'Europe/Zagreb', $this->timezoneChoices()),
            new SettingDefinition('app_date_format', SettingType::STRING, 'Format datuma', 'PHP date() format, npr. d.m.Y.', 'd.m.Y.'),
        ]);

        yield new SettingsSection('email', 'Email', 'fa-envelope', [
            new SettingDefinition('mail_from_name', SettingType::STRING, 'Pošiljatelj (ime)', 'Ime u polju From odlaznih mailova.', 'Tallyst'),
            new SettingDefinition('mail_from_email', SettingType::EMAIL, 'Pošiljatelj (email)', 'Adresa u polju From.', ''),
            new SettingDefinition('mail_reply_to', SettingType::EMAIL, 'Reply-To', 'Neobavezno; odgovori idu na ovu adresu.', ''),
            new SettingDefinition('smtp_host', SettingType::STRING, 'SMTP host', 'Npr. smtp.mailtrap.io. Prazno = koristi MAILER_DSN iz okoline.', ''),
            new SettingDefinition('smtp_port', SettingType::INT, 'SMTP port', '587 za STARTTLS, 465 za SSL.', 587),
            new SettingDefinition('smtp_username', SettingType::STRING, 'SMTP korisnik', '', ''),
            new SettingDefinition('smtp_password', SettingType::PASSWORD, 'SMTP lozinka', 'Šifrirano. Ostavi prazno da zadržiš trenutnu.', null, [], true),
            new SettingDefinition('smtp_encryption', SettingType::CHOICE, 'Enkripcija', '', 'tls', [
                'STARTTLS (tls)' => 'tls',
                'SSL/TLS (ssl)' => 'ssl',
                'Bez enkripcije' => 'none',
            ]),
        ]);
    }

    /**
     * @return array<string, string> label => value (label == value for timezones)
     */
    private function timezoneChoices(): array
    {
        $choices = [];
        foreach (\DateTimeZone::listIdentifiers() as $tz) {
            $choices[$tz] = $tz;
        }

        return $choices;
    }
}
