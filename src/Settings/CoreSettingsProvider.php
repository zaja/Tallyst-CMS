<?php

namespace App\Settings;

use App\Repository\MenuRepository;

/**
 * The Core settings sections (v1): General, Branding, Localization, Email, Footer. Modules may
 * add their own sections later via SettingsSectionProviderInterface.
 *
 * `site_name` lives in General (its single editable home). Branding holds the visual identity
 * (logo + favicon, Media references stored as loose ids). Footer holds the configurable site
 * footer. The front reads the same Setting keys regardless.
 */
class CoreSettingsProvider implements SettingsSectionProviderInterface
{
    public function __construct(
        private readonly MenuRepository $menus,
    ) {
    }

    public function getSettingsSections(): iterable
    {
        yield new SettingsSection('general', 'Općenito', 'fa-sliders', [
            new SettingDefinition('site_name', SettingType::STRING, 'Naziv sajta', 'Prikazuje se u zaglavlju i naslovu.', 'Tallyst'),
            new SettingDefinition('site_tagline', SettingType::STRING, 'Slogan', 'Kratak opis sajta (meta opis).', ''),
            new SettingDefinition('search_enabled', SettingType::BOOL, 'Prikaži tražilicu', 'Polje za pretragu u zaglavlju. Isključeno (zadano) = jednostavan sajt bez pretrage; uključi ako imaš puno sadržaja.', false),
        ]);

        yield new SettingsSection('branding', 'Branding', 'fa-palette', [
            new SettingDefinition('logo_media_id', SettingType::MEDIA, 'Logo', 'Prikazuje se u zaglavlju. Prazno = naziv sajta kao tekst.'),
            new SettingDefinition('favicon_media_id', SettingType::MEDIA, 'Favicon', 'Ikona u kartici preglednika (PNG/JPG, kvadratna ~64px).'),
        ]);

        yield new SettingsSection('blog', 'Blog', 'fa-newspaper', [
            new SettingDefinition('blog_posts_per_page', SettingType::INT, 'Objava po stranici', 'Koliko objava prikazati po stranici na blogu i arhivama (1–50).', 9),
        ]);

        yield new SettingsSection('maintenance', 'Održavanje', 'fa-screwdriver-wrench', [
            new SettingDefinition('maintenance_enabled', SettingType::BOOL, 'Maintenance mode', 'Posjetitelji vide stranicu održavanja (503); admin i dalje radi i može ući.', false),
            new SettingDefinition('maintenance_message', SettingType::RICH_TEXT, 'Poruka', 'Prikazuje se posjetiteljima dok je održavanje uključeno.', '<p>Stranica je trenutno u održavanju. Vraćamo se uskoro.</p>'),
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
            new SettingDefinition('order_admin_email', SettingType::EMAIL, 'Email za narudžbe (admin)', 'Prima obavijest o novoj plaćenoj narudžbi. Prazno = ORDER_ADMIN_EMAIL iz okoline.', ''),
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

        yield new SettingsSection('footer', 'Footer', 'fa-window-minimize', [
            new SettingDefinition('footer_columns', SettingType::CHOICE, 'Broj kolona', 'Jedna kolona = samo tekst; dvije = tekst + izbornik.', '2', [
                '1 kolona' => '1',
                '2 kolone' => '2',
            ]),
            new SettingDefinition('footer_text', SettingType::RICH_TEXT, 'Tekst', 'Prikazuje se u prvoj koloni footera.'),
            new SettingDefinition('footer_menu', SettingType::CHOICE, 'Izbornik (2. kolona)', 'Postojeći izbornik prikazan u drugoj koloni (kad su 2 kolone).', '', $this->menuChoices()),
            new SettingDefinition('footer_copyright', SettingType::STRING, 'Copyright', 'Prazno = automatski "© {godina} {naziv sajta}".', ''),
            new SettingDefinition('footer_show_powered_by', SettingType::BOOL, 'Prikaži "Pokreće Tallyst CMS"', '', true),
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

    /**
     * Existing menus as choices: label = menu name, value = menu LOCATION (what render_menu
     * consumes). The non-required ChoiceType auto-adds an empty "none" option.
     *
     * @return array<string, string> name => location
     */
    private function menuChoices(): array
    {
        $choices = [];
        foreach ($this->menus->findAll() as $menu) {
            $choices[$menu->getName()] = $menu->getLocation();
        }

        return $choices;
    }
}
