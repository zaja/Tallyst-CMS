<?php

namespace App\Settings;

use App\Icon\IconRegistry;
use App\Repository\MenuRepository;

/**
 * The Core settings sections (v1): General, Branding, Localization, Email, Footer, Top bar. Modules
 * may add their own sections later via SettingsSectionProviderInterface.
 *
 * `site_name` lives in General (its single editable home). Branding holds the visual identity
 * (logo + favicon, Media references stored as loose ids). Footer holds the configurable site
 * footer. Top bar holds the optional thin bar above the header. The front reads the same Setting
 * keys regardless.
 */
class CoreSettingsProvider implements SettingsSectionProviderInterface
{
    public function __construct(
        private readonly MenuRepository $menus,
        private readonly IconRegistry $icons,
        private readonly \App\Font\FontRegistry $fonts,
    ) {
    }

    public function getSettingsSections(): iterable
    {
        // Labels/help/section-titles/descriptive-choices are `admin`-domain keys (translated by the
        // settings form). Choice VALUES are untouched; language-name choices (English/Hrvatski) and
        // dynamic choices (timezones, menus) stay literal.
        yield new SettingsSection('general', 'admin.settings.general.title', 'fa-sliders', [
            new SettingDefinition('site_name', SettingType::STRING, 'admin.settings.general.site_name.label', 'admin.settings.general.site_name.help', 'Tallyst'),
            new SettingDefinition('site_tagline', SettingType::STRING, 'admin.settings.general.site_tagline.label', 'admin.settings.general.site_tagline.help', ''),
            new SettingDefinition('search_enabled', SettingType::BOOL, 'admin.settings.general.search_enabled.label', 'admin.settings.general.search_enabled.help', false),
            // Hide the Demo content link from the sidebar (for production, where the demo tools
            // are no longer needed). Only hides the LINK — the route/page stay reachable directly.
            new SettingDefinition('hide_demo_link', SettingType::BOOL, 'admin.settings.general.hide_demo_link.label', 'admin.settings.general.hide_demo_link.help', false),
        ]);

        // Front branding (logo/favicon) + separate ADMIN branding (white-label the back-office):
        // admin_logo replaces the "Tallyst CMS" title, admin_favicon the admin tab icon. All loose
        // Media id references; null-safe fallbacks in DashboardController when unset.
        yield new SettingsSection('branding', 'admin.settings.branding.title', 'fa-palette', [
            new SettingDefinition('logo_media_id', SettingType::MEDIA, 'admin.settings.branding.logo_media_id.label', 'admin.settings.branding.logo_media_id.help'),
            new SettingDefinition('favicon_media_id', SettingType::MEDIA, 'admin.settings.branding.favicon_media_id.label', 'admin.settings.branding.favicon_media_id.help'),
            new SettingDefinition('admin_logo_media_id', SettingType::MEDIA, 'admin.settings.branding.admin_logo_media_id.label', 'admin.settings.branding.admin_logo_media_id.help'),
            new SettingDefinition('admin_favicon_media_id', SettingType::MEDIA, 'admin.settings.branding.admin_favicon_media_id.label', 'admin.settings.branding.admin_favicon_media_id.help'),
        ]);

        yield new SettingsSection('blog', 'admin.settings.blog.title', 'fa-newspaper', [
            new SettingDefinition('blog_posts_per_page', SettingType::INT, 'admin.settings.blog.blog_posts_per_page.label', 'admin.settings.blog.blog_posts_per_page.help', 9),
        ]);

        yield new SettingsSection('maintenance', 'admin.settings.maintenance.title', 'fa-screwdriver-wrench', [
            new SettingDefinition('maintenance_enabled', SettingType::BOOL, 'admin.settings.maintenance.maintenance_enabled.label', 'admin.settings.maintenance.maintenance_enabled.help', false),
            new SettingDefinition('maintenance_message', SettingType::RICH_TEXT, 'admin.settings.maintenance.maintenance_message.label', 'admin.settings.maintenance.maintenance_message.help', '<p>Stranica je trenutno u održavanju. Vraćamo se uskoro.</p>'),
        ]);

        yield new SettingsSection('localization', 'admin.settings.localization.title', 'fa-globe', [
            new SettingDefinition('app_locale', SettingType::CHOICE, 'admin.settings.localization.app_locale.label', 'admin.settings.localization.app_locale.help', 'en', [
                // Language names render in their OWN language (not translated).
                'English' => 'en',
                'Hrvatski' => 'hr',
            ]),
            new SettingDefinition('app_timezone', SettingType::CHOICE, 'admin.settings.localization.app_timezone.label', 'admin.settings.localization.app_timezone.help', 'Europe/Zagreb', $this->timezoneChoices()),
            new SettingDefinition('app_date_format', SettingType::STRING, 'admin.settings.localization.app_date_format.label', 'admin.settings.localization.app_date_format.help', 'd.m.Y.'),
        ]);

        yield new SettingsSection('email', 'admin.settings.email.title', 'fa-envelope', [
            new SettingDefinition('mail_from_name', SettingType::STRING, 'admin.settings.email.mail_from_name.label', 'admin.settings.email.mail_from_name.help', 'Tallyst'),
            new SettingDefinition('mail_from_email', SettingType::EMAIL, 'admin.settings.email.mail_from_email.label', 'admin.settings.email.mail_from_email.help', ''),
            new SettingDefinition('mail_reply_to', SettingType::EMAIL, 'admin.settings.email.mail_reply_to.label', 'admin.settings.email.mail_reply_to.help', ''),
            new SettingDefinition('order_admin_email', SettingType::EMAIL, 'admin.settings.email.order_admin_email.label', 'admin.settings.email.order_admin_email.help', ''),
            new SettingDefinition('smtp_host', SettingType::STRING, 'admin.settings.email.smtp_host.label', 'admin.settings.email.smtp_host.help', ''),
            new SettingDefinition('smtp_port', SettingType::INT, 'admin.settings.email.smtp_port.label', 'admin.settings.email.smtp_port.help', 587),
            new SettingDefinition('smtp_username', SettingType::STRING, 'admin.settings.email.smtp_username.label', '', ''),
            new SettingDefinition('smtp_password', SettingType::PASSWORD, 'admin.settings.email.smtp_password.label', 'admin.settings.email.smtp_password.help', null, [], true),
            new SettingDefinition('smtp_encryption', SettingType::CHOICE, 'admin.settings.email.smtp_encryption.label', '', 'tls', [
                'admin.settings.email.smtp_encryption.choice.tls' => 'tls',
                'admin.settings.email.smtp_encryption.choice.ssl' => 'ssl',
                'admin.settings.email.smtp_encryption.choice.none' => 'none',
            ]),
        ]);

        // Footer: 1–4 columns, each a menu (its name is the heading) OR rich text — the type is
        // IMPLICIT (menu wins when both are set, text otherwise), so no conditional field is needed
        // (the schema-driven form can't hide fields). copyright + powered-by are the branding line.
        yield new SettingsSection('footer', 'admin.settings.footer.title', 'fa-window-minimize', [
            new SettingDefinition('footer_columns', SettingType::CHOICE, 'admin.settings.footer.footer_columns.label', 'admin.settings.footer.footer_columns.help', '2', [
                'admin.settings.footer.footer_columns.choice.1' => '1',
                'admin.settings.footer.footer_columns.choice.2' => '2',
                'admin.settings.footer.footer_columns.choice.3' => '3',
                'admin.settings.footer.footer_columns.choice.4' => '4',
            ]),
            ...$this->footerColumnDefinitions(),
            new SettingDefinition('footer_copyright', SettingType::STRING, 'admin.settings.footer.footer_copyright.label', 'admin.settings.footer.footer_copyright.help', ''),
            new SettingDefinition('footer_show_powered_by', SettingType::BOOL, 'admin.settings.footer.footer_show_powered_by.label', '', true),
        ]);

        // Top bar: an optional thin bar above the header. Left = rich text (links); right = social
        // icons — one optional URL field PER brand icon (generated from IconRegistry::brandKeys(),
        // NOT a hardcoded list, so adding a brand icon adds its field automatically). An icon shows
        // on the front only when its URL is set. Labels are dynamic literals (like timezones/menus).
        yield new SettingsSection('topbar', 'admin.settings.topbar.title', 'fa-window-maximize', [
            new SettingDefinition('top_bar_enabled', SettingType::BOOL, 'admin.settings.topbar.top_bar_enabled.label', 'admin.settings.topbar.top_bar_enabled.help', false),
            new SettingDefinition('top_bar_text', SettingType::RICH_TEXT, 'admin.settings.topbar.top_bar_text.label', 'admin.settings.topbar.top_bar_text.help'),
            ...$this->socialDefinitions(),
        ]);

        // Typography: pick a system font for headings/display and one for body text. Choices come
        // from the curated Core FontRegistry. Defaults = the Tema v2 design typography
        // (Space Grotesk display / Inter body) so a fresh install looks like the approved design
        // out of the box; 'system' (the OS stack) stays a choice.
        yield new SettingsSection('typography', 'admin.settings.typography.title', 'fa-font', [
            new SettingDefinition('display_font', SettingType::CHOICE, 'admin.settings.typography.display_font.label', 'admin.settings.typography.display_font.help', 'space-grotesk', $this->fonts->choices()),
            new SettingDefinition('body_font', SettingType::CHOICE, 'admin.settings.typography.body_font.label', 'admin.settings.typography.body_font.help', 'inter', $this->fonts->choices()),
        ]);
    }

    /**
     * One optional URL setting per brand icon (social_{brand}_url), built from brandKeys() so the
     * list is single-sourced with the icon registry. Label is a dynamic literal ("Github URL").
     *
     * @return SettingDefinition[]
     */
    private function socialDefinitions(): array
    {
        $defs = [];
        foreach ($this->icons->brandKeys() as $key) {
            $defs[] = new SettingDefinition(
                'social_'.$key.'_url',
                SettingType::STRING,
                ucfirst($key).' URL',
                'admin.settings.topbar.social_url.help',
                '',
            );
        }

        return $defs;
    }

    /**
     * Per-column footer fields (col 1–4): an optional menu choice + optional rich text each. The
     * type is implicit — the layout renders the menu when set, else the text. Only columns up to
     * `footer_columns` are shown on the front.
     *
     * @return SettingDefinition[]
     */
    private function footerColumnDefinitions(): array
    {
        $defs = [];
        for ($n = 1; $n <= 4; ++$n) {
            $defs[] = new SettingDefinition(
                'footer_col'.$n.'_menu',
                SettingType::CHOICE,
                'admin.settings.footer.col'.$n.'_menu.label',
                'admin.settings.footer.col_menu.help',
                '',
                $this->menuChoices(),
            );
            $defs[] = new SettingDefinition(
                'footer_col'.$n.'_text',
                SettingType::RICH_TEXT,
                'admin.settings.footer.col'.$n.'_text.label',
                'admin.settings.footer.col_text.help',
            );
        }

        return $defs;
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
