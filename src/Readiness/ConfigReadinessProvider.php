<?php

namespace App\Readiness;

use App\Settings\SettingsManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Config/security/mail readiness checks derived from env scalars + the Settings layer. Pure
 * (no filesystem/DB), so it is fully unit-testable with controlled constructor values + a stub
 * SettingsManager. Honest about nuance: APP_ENV=dev is a WARNING ("for production set prod"),
 * not a hard error — local dev is legitimate.
 */
class ConfigReadinessProvider implements ReadinessCheckProviderInterface
{
    // Group names are `admin`-domain translation keys (translated via t()). Check LABELS that are env-var
    // names stay literal (universal); descriptive labels + detail/fix are keys with %param% placeholders.
    private const G_SECURITY = 'admin.readiness.group.security';
    private const G_CONFIG = 'admin.readiness.group.config';
    private const G_MAIL = 'admin.readiness.group.mail';
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
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** @param array<string, string|int> $params */
    private function t(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $params, 'admin');
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
        $g = $this->t(self::G_SECURITY);
        if ('' === trim($this->appSecret)) {
            return Check::problem($g, 'APP_SECRET',
                $this->t('admin.readiness.app_secret.detail.empty'),
                $this->t('admin.readiness.app_secret.fix'));
        }

        return Check::ok($g, 'APP_SECRET', $this->t('admin.readiness.app_secret.detail.ok'));
    }

    private function checkEncryptionKey(): Check
    {
        $g = $this->t(self::G_SECURITY);
        $decoded = base64_decode($this->encryptionKey, true);
        if ('' === trim($this->encryptionKey) || false === $decoded || 32 !== \strlen($decoded)) {
            return Check::problem($g, 'SETTINGS_ENCRYPTION_KEY',
                $this->t('admin.readiness.enc_key.detail.bad'),
                $this->t('admin.readiness.enc_key.fix'));
        }

        return Check::ok($g, 'SETTINGS_ENCRYPTION_KEY', $this->t('admin.readiness.enc_key.detail.ok'));
    }

    private function checkHttps(): Check
    {
        $g = $this->t(self::G_SECURITY);
        if ('https' === $this->scheme()) {
            return Check::ok($g, 'HTTPS', $this->t('admin.readiness.https.detail.ok'));
        }

        return Check::warning($g, 'HTTPS',
            $this->t('admin.readiness.https.detail.warn'),
            $this->t('admin.readiness.https.fix'));
    }

    private function checkAppEnv(): Check
    {
        $g = $this->t(self::G_CONFIG);
        if ('prod' === $this->appEnv) {
            return Check::ok($g, 'APP_ENV', $this->t('admin.readiness.app_env.detail.ok'));
        }

        return Check::warning($g, 'APP_ENV',
            $this->t('admin.readiness.app_env.detail.dev', ['%env%' => $this->appEnv]),
            $this->t('admin.readiness.app_env.fix'));
    }

    private function checkDefaultUri(): Check
    {
        $g = $this->t(self::G_CONFIG);
        $uri = trim($this->defaultUri);
        $host = '' === $uri ? null : parse_url($uri, \PHP_URL_HOST);

        if ('' === $uri || !\in_array($this->scheme(), ['http', 'https'], true) || null === $host) {
            return Check::warning($g, 'DEFAULT_URI',
                $this->t('admin.readiness.default_uri.detail.invalid'),
                $this->t('admin.readiness.default_uri.fix.invalid'));
        }
        if (\in_array($host, ['localhost', '127.0.0.1'], true)) {
            return Check::warning($g, 'DEFAULT_URI',
                $this->t('admin.readiness.default_uri.detail.localhost', ['%uri%' => $uri]),
                $this->t('admin.readiness.default_uri.fix.localhost'));
        }

        return Check::ok($g, 'DEFAULT_URI', $this->t('admin.readiness.default_uri.detail.ok', ['%uri%' => $uri]));
    }

    private function checkMailer(): Check
    {
        $g = $this->t(self::G_MAIL);
        $label = $this->t('admin.readiness.mailer.label');
        if ('' !== (string) $this->settings->get('smtp_host')) {
            return Check::ok($g, $label, $this->t('admin.readiness.mailer.detail.db', ['%host%' => (string) $this->settings->get('smtp_host')]));
        }
        if ('' !== trim($this->mailerDsn) && 'null://null' !== trim($this->mailerDsn)) {
            return Check::ok($g, $label, $this->t('admin.readiness.mailer.detail.env'));
        }

        return Check::warning($g, $label,
            $this->t('admin.readiness.mailer.detail.unconfigured'),
            $this->t('admin.readiness.mailer.fix'));
    }

    private function checkSmtpDecryptable(): ?Check
    {
        if ('' === (string) $this->settings->get('smtp_host')) {
            return null; // no DB SMTP → nothing to decrypt
        }
        if ($this->settings->isEncryptedValueReadable('smtp_password')) {
            return null;
        }

        return Check::problem($this->t(self::G_MAIL), $this->t('admin.readiness.smtp_password.label'),
            $this->t('admin.readiness.smtp_password.detail'),
            $this->t('admin.readiness.smtp_password.fix'));
    }

    private function checkMailFrom(): Check
    {
        $g = $this->t(self::G_MAIL);
        $label = $this->t('admin.readiness.mail_from.label');
        $from = (string) $this->settings->get('mail_from_email');
        if ('' === trim($from)) {
            return Check::warning($g, $label,
                $this->t('admin.readiness.mail_from.detail.warn'),
                $this->t('admin.readiness.mail_from.fix'));
        }

        return Check::ok($g, $label, $this->t('admin.readiness.mail_from.detail.ok', ['%from%' => $from]));
    }

    private function checkOrderAdminEmail(): Check
    {
        $g = $this->t(self::G_MAIL);
        $label = $this->t('admin.readiness.order_email.label');
        $email = (string) ($this->settings->get('order_admin_email') ?: $this->orderAdminEnv);
        if ('' === trim($email) || self::PLACEHOLDER_ORDER_EMAIL === $email) {
            $detail = '' === trim($email)
                ? $this->t('admin.readiness.order_email.detail.unset')
                : $this->t('admin.readiness.order_email.detail.placeholder', ['%placeholder%' => self::PLACEHOLDER_ORDER_EMAIL]);

            return Check::warning($g, $label, $detail, $this->t('admin.readiness.order_email.fix'));
        }

        return Check::ok($g, $label, $this->t('admin.readiness.order_email.detail.ok', ['%email%' => $email]));
    }

    private function scheme(): string
    {
        return strtolower((string) parse_url(trim($this->defaultUri), \PHP_URL_SCHEME));
    }
}
