<?php

namespace App\Tests\Mailer;

use App\Mailer\MailProviderRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Pure data-layer tests: the registry/definition are the single source of truth the
 * "vodeće načelo" build depends on — the CHOICE list, the field-ownership lookup the
 * settings-form reveal uses, DSN construction, and the isConfigured() gate.
 */
class MailProviderRegistryTest extends TestCase
{
    public function testChoicesListsAllFiveProviders(): void
    {
        $choices = (new MailProviderRegistry())->choices();

        self::assertSame([
            'SMTP' => 'smtp',
            'Resend' => 'resend',
            'Mailgun' => 'mailgun',
            'Postmark' => 'postmark',
            'Brevo' => 'brevo',
        ], $choices);
    }

    public function testProviderKeyForFieldFindsTheOwningProvider(): void
    {
        $registry = new MailProviderRegistry();

        self::assertSame('smtp', $registry->providerKeyForField('smtp_host'));
        self::assertSame('smtp', $registry->providerKeyForField('smtp_password'));
        self::assertSame('resend', $registry->providerKeyForField('resend_api_key'));
        self::assertSame('mailgun', $registry->providerKeyForField('mailgun_api_key'));
        self::assertSame('mailgun', $registry->providerKeyForField('mailgun_domain'));
        self::assertSame('mailgun', $registry->providerKeyForField('mailgun_region'));
        self::assertSame('postmark', $registry->providerKeyForField('postmark_server_token'));
        self::assertSame('brevo', $registry->providerKeyForField('brevo_api_key'));
        self::assertNull($registry->providerKeyForField('mail_from_email'), 'a Sender field belongs to no provider');
    }

    public function testAllFieldKeysUnionsEveryProvider(): void
    {
        $keys = (new MailProviderRegistry())->allFieldKeys();

        self::assertSame([
            'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption',
            'resend_api_key',
            'mailgun_api_key', 'mailgun_domain', 'mailgun_region',
            'postmark_server_token',
            'brevo_api_key',
        ], $keys);
    }

    public function testMailgunDefinitionBuildsDsnWithDomainAndRegion(): void
    {
        $mailgun = (new MailProviderRegistry())->get('mailgun');

        self::assertNotNull($mailgun);
        self::assertSame(
            'mailgun+api://key123:mg.example.com@default?region=eu',
            $mailgun->buildDsn(['mailgun_api_key' => 'key123', 'mailgun_domain' => 'mg.example.com', 'mailgun_region' => 'eu']),
        );
    }

    public function testMailgunIsConfiguredOnlyWithKeyAndDomain(): void
    {
        $mailgun = (new MailProviderRegistry())->get('mailgun');

        self::assertNotNull($mailgun);
        self::assertTrue($mailgun->isConfigured(['mailgun_api_key' => 'k', 'mailgun_domain' => 'd', 'mailgun_region' => 'us']));
        self::assertFalse($mailgun->isConfigured(['mailgun_api_key' => 'k', 'mailgun_domain' => '', 'mailgun_region' => 'us']), 'domain missing');
        self::assertFalse($mailgun->isConfigured(['mailgun_api_key' => '', 'mailgun_domain' => 'd', 'mailgun_region' => 'us']), 'key missing');
    }

    public function testPostmarkDefinitionBuildsDsn(): void
    {
        $postmark = (new MailProviderRegistry())->get('postmark');

        self::assertNotNull($postmark);
        self::assertSame('postmark+api://srv_token_123@default', $postmark->buildDsn(['postmark_server_token' => 'srv_token_123']));
        self::assertTrue($postmark->isConfigured(['postmark_server_token' => 'x']));
        self::assertFalse($postmark->isConfigured(['postmark_server_token' => '']));
    }

    public function testBrevoDefinitionBuildsDsn(): void
    {
        $brevo = (new MailProviderRegistry())->get('brevo');

        self::assertNotNull($brevo);
        self::assertSame('brevo+api://key_abc@default', $brevo->buildDsn(['brevo_api_key' => 'key_abc']));
        self::assertTrue($brevo->isConfigured(['brevo_api_key' => 'x']));
        self::assertFalse($brevo->isConfigured(['brevo_api_key' => '']));
    }

    public function testResendDefinitionBuildsAndUrlEncodesTheDsn(): void
    {
        $resend = (new MailProviderRegistry())->get('resend');

        self::assertNotNull($resend);
        // A key containing DSN-hostile characters (/, @, :) must still round-trip safely.
        self::assertSame(
            'resend+api://key%2Fwith%40special%3Achars@default',
            $resend->buildDsn(['resend_api_key' => 'key/with@special:chars']),
        );
    }

    public function testResendIsConfiguredOnlyWithANonEmptyKey(): void
    {
        $resend = (new MailProviderRegistry())->get('resend');

        self::assertNotNull($resend);
        self::assertTrue($resend->isConfigured(['resend_api_key' => 're_abc123']));
        self::assertFalse($resend->isConfigured(['resend_api_key' => '']));
        self::assertFalse($resend->isConfigured([]), 'a missing key is treated as empty, not a crash');
    }

    public function testUnknownProviderKeyReturnsNull(): void
    {
        self::assertNull((new MailProviderRegistry())->get('does-not-exist'));
    }
}
