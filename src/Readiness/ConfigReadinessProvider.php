<?php

namespace App\Readiness;

use App\Settings\SettingsManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Config/security/mail readiness checks derived from env scalars + the Settings layer. Pure
 * (no filesystem/DB), so it is fully unit-testable with controlled constructor values + a stub
 * SettingsManager. Honest about nuance: APP_ENV=dev is a WARNING ("for production set prod"),
 * not a hard error — local dev is legitimate.
 */
class ConfigReadinessProvider implements ReadinessCheckProviderInterface
{
    private const G_SECURITY = 'Sigurnost';
    private const G_CONFIG = 'Konfiguracija';
    private const G_MAIL = 'Pošta';
    private const PLACEHOLDER_ORDER_EMAIL = 'admin@tallyst.local';

    public function __construct(
        #[Autowire('%kernel.environment%')]
        private readonly string $appEnv,
        #[Autowire('%kernel.secret%')]
        private readonly string $appSecret,
        #[Autowire('%env(DEFAULT_URI)%')]
        private readonly string $defaultUri,
        #[Autowire('%env(SETTINGS_ENCRYPTION_KEY)%')]
        private readonly string $encryptionKey,
        #[Autowire('%env(MAILER_DSN)%')]
        private readonly string $mailerDsn,
        #[Autowire('%env(ORDER_ADMIN_EMAIL)%')]
        private readonly string $orderAdminEnv,
        private readonly SettingsManager $settings,
    ) {
    }

    public function getChecks(): iterable
    {
        yield $this->checkAppSecret();
        yield $this->checkEncryptionKey();
        yield $this->checkHttps();
        yield $this->checkAppEnv();
        yield $this->checkDefaultUri();
        yield $this->checkMailer();
        if (null !== $c = $this->checkSmtpDecryptable()) {
            yield $c;
        }
        yield $this->checkMailFrom();
        yield $this->checkOrderAdminEmail();
    }

    private function checkAppSecret(): Check
    {
        if ('' === trim($this->appSecret)) {
            return Check::problem(self::G_SECURITY, 'APP_SECRET',
                'Prazan — CSRF tokeni i potpisani URL-ovi nisu sigurni.',
                'app:install ga generira u .env.local; ili ručno postavi APP_SECRET na 32+ nasumičnih znakova.');
        }

        return Check::ok(self::G_SECURITY, 'APP_SECRET', 'Postavljen.');
    }

    private function checkEncryptionKey(): Check
    {
        $decoded = base64_decode($this->encryptionKey, true);
        if ('' === trim($this->encryptionKey) || false === $decoded || 32 !== \strlen($decoded)) {
            return Check::problem(self::G_SECURITY, 'SETTINGS_ENCRYPTION_KEY',
                'Nedostaje ili nije ispravan (treba base64 od 32 bajta) — enkripcija postavki (SMTP lozinka) ne radi.',
                "Pokreni app:install, ili: php8.5 -r 'echo base64_encode(random_bytes(32));' → upiši u .env.local.");
        }

        return Check::ok(self::G_SECURITY, 'SETTINGS_ENCRYPTION_KEY', 'Postavljen (32 bajta).');
    }

    private function checkHttps(): Check
    {
        if ('https' === $this->scheme()) {
            return Check::ok(self::G_SECURITY, 'HTTPS',
                'DEFAULT_URI koristi https. (Ne potvrđuje stvarni TLS na serveru — to provjeri u pregledniku.)');
        }

        return Check::warning(self::G_SECURITY, 'HTTPS',
            'DEFAULT_URI nije https — produkcija bi trebala posluživati preko HTTPS-a.',
            'Postavi SSL (CloudPanel/nginx) i DEFAULT_URI na https://… .');
    }

    private function checkAppEnv(): Check
    {
        if ('prod' === $this->appEnv) {
            return Check::ok(self::G_CONFIG, 'APP_ENV', 'Radi u prod modu.');
        }

        return Check::warning(self::G_CONFIG, 'APP_ENV',
            sprintf('Trenutno "%s". Lokalni razvoj je u redu; za PRODUKCIJU postavi prod (dev izlaže detaljne greške i usporava).', $this->appEnv),
            'U .env.local postavi APP_ENV=prod, pa cache:clear — prije nego javna domena ide uživo.');
    }

    private function checkDefaultUri(): Check
    {
        $uri = trim($this->defaultUri);
        $host = '' === $uri ? null : parse_url($uri, \PHP_URL_HOST);

        if ('' === $uri || !\in_array($this->scheme(), ['http', 'https'], true) || null === $host) {
            return Check::warning(self::G_CONFIG, 'DEFAULT_URI',
                'Nije postavljen apsolutni URL — mailovi (reset lozinke) i sitemap koristit će http://localhost.',
                'Postavi DEFAULT_URI na pravi javni URL (npr. https://tvoja-domena.hr) u .env.local.');
        }
        if (\in_array($host, ['localhost', '127.0.0.1'], true)) {
            return Check::warning(self::G_CONFIG, 'DEFAULT_URI',
                sprintf('Postavljen na "%s" — worker-generirani linkovi (reset lozinke) vodit će na localhost.', $uri),
                'Za produkciju postavi DEFAULT_URI na pravu javnu domenu.');
        }

        return Check::ok(self::G_CONFIG, 'DEFAULT_URI', sprintf('Postavljen: %s', $uri));
    }

    private function checkMailer(): Check
    {
        if ('' !== (string) $this->settings->get('smtp_host')) {
            return Check::ok(self::G_MAIL, 'Pošta (SMTP)', sprintf('DB SMTP konfiguriran (%s).', (string) $this->settings->get('smtp_host')));
        }
        if ('' !== trim($this->mailerDsn) && 'null://null' !== trim($this->mailerDsn)) {
            return Check::ok(self::G_MAIL, 'Pošta (SMTP)', 'Koristi MAILER_DSN iz okoline.');
        }

        return Check::warning(self::G_MAIL, 'Pošta (SMTP)',
            'Nije konfigurirana — reset lozinke i mailovi narudžbi neće se slati.',
            'Postavke → Email: upiši SMTP host/korisnika/lozinku (ili postavi MAILER_DSN u .env.local).');
    }

    private function checkSmtpDecryptable(): ?Check
    {
        if ('' === (string) $this->settings->get('smtp_host')) {
            return null; // no DB SMTP → nothing to decrypt
        }
        if ($this->settings->isEncryptedValueReadable('smtp_password')) {
            return null;
        }

        return Check::problem(self::G_MAIL, 'SMTP lozinka',
            'Nije moguće dekriptirati (ključ rotiran/izgubljen) — mailer pada na env fallback.',
            'Postavke → Email: ponovno upiši SMTP lozinku (re-enkriptira se trenutnim ključem).');
    }

    private function checkMailFrom(): Check
    {
        $from = (string) $this->settings->get('mail_from_email');
        if ('' === trim($from)) {
            return Check::warning(self::G_MAIL, 'Adresa pošiljatelja',
                'mail_from_email nije postavljen — pravi SMTP serveri odbijaju mail bez ispravnog From-a (553).',
                'Postavke → Email: postavi "Email pošiljatelja" na adresu koju SMTP račun smije slati.');
        }

        return Check::ok(self::G_MAIL, 'Adresa pošiljatelja', sprintf('Postavljena: %s', $from));
    }

    private function checkOrderAdminEmail(): Check
    {
        $email = (string) ($this->settings->get('order_admin_email') ?: $this->orderAdminEnv);
        if ('' === trim($email) || self::PLACEHOLDER_ORDER_EMAIL === $email) {
            return Check::warning(self::G_MAIL, 'Email za narudžbe',
                ('' === trim($email) ? 'Nije postavljen' : 'Postavljen na placeholder "'.self::PLACEHOLDER_ORDER_EMAIL.'"')
                    .' — obavijesti o narudžbama neće stići na pravu adresu.',
                'Postavke → Email: postavi "Email za narudžbe" na pravu, dostavljivu adresu (ili ORDER_ADMIN_EMAIL u .env.local).');
        }

        return Check::ok(self::G_MAIL, 'Email za narudžbe', sprintf('Postavljen: %s', $email));
    }

    private function scheme(): string
    {
        return strtolower((string) parse_url(trim($this->defaultUri), \PHP_URL_SCHEME));
    }
}
