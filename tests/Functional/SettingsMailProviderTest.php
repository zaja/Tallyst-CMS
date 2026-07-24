<?php

namespace App\Tests\Functional;

use App\Entity\Setting;
use App\Entity\User;
use App\Mailer\MailProviderRegistry;
use App\Settings\SettingsManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Mail provider picker (Postavke → Email → Delivery). Locks:
 *  - the Email tab renders Sender + Delivery as labelled sub-sections with EVERY provider's
 *    fields present in the DOM (the JS reveal is presentation-only — a hidden field still
 *    submits, which is exactly what the round-trip test below relies on to prove nothing is
 *    lost across a provider switch, without needing a browser);
 *  - cycling through all five providers (and back to smtp) never clears any OTHER provider's
 *    stored fields, in any order;
 *  - the "restart your worker" notice is contextual (only on an actual mail-delivery change,
 *    never on an unrelated Sender-field save);
 *  - EVERY MailProviderRegistry entry's fields carry the Stimulus data-attributes the reveal
 *    JS depends on, and the select itself carries its target on the ACTUAL <select> element
 *    (a real, previously-shipped bug: the target sat on the wrapping row div instead, which
 *    has no .value — see testEveryRegisteredProviderHasCorrectlyTaggedFields()). This is a
 *    pure server-render assertion, no JS execution needed — a 6th provider added to the
 *    registry without matching tags fails HERE, not silently in a browser.
 *
 * Needs the migrated test DB (see AdminAccessTest/SettingsTabTest).
 */
class SettingsMailProviderTest extends WebTestCase
{
    private const TOUCHED_SETTINGS = [
        'mail_from_name', 'mail_provider', 'smtp_host',
        'resend_api_key', 'mailgun_api_key', 'mailgun_domain', 'mailgun_region',
        'postmark_server_token', 'brevo_api_key',
    ];

    /** @var string[] */
    private array $createdEmails = [];

    public function testEmailTabRendersSenderAndDeliveryWithBothProvidersFields(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        $crawler = $client->request('GET', '/admin/settings/email');
        self::assertResponseIsSuccessful();

        self::assertGreaterThan(0, $crawler->filter('#email')->count(), 'Sender sub-section');
        self::assertGreaterThan(0, $crawler->filter('#email_delivery')->count(), 'Delivery sub-section');
        self::assertGreaterThan(0, $crawler->filter('[name="form[mail_provider]"]')->count());
        foreach (['smtp_host', 'resend_api_key', 'mailgun_api_key', 'mailgun_domain', 'mailgun_region', 'postmark_server_token', 'brevo_api_key'] as $field) {
            self::assertGreaterThan(0, $crawler->filter('[name="form['.$field.']"]')->count(), "$field stays in the DOM (CSS-hidden, not removed)");
        }
    }

    public function testEveryRegisteredProviderHasCorrectlyTaggedFields(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());
        $registry = static::getContainer()->get(MailProviderRegistry::class);

        $crawler = $client->request('GET', '/admin/settings/email');
        self::assertResponseIsSuccessful();

        // The Stimulus TARGET (its .value is read on 'change') must be on the real <select>
        // element itself, not a wrapping row — a row div has no .value. This exact placement
        // was the shipped bug (see class docblock).
        $select = $crawler->filter('select[name="form[mail_provider]"]');
        self::assertGreaterThan(0, $select->count(), 'the mail_provider <select> must exist');
        self::assertSame('select', $select->attr('data-admin--mail-provider-target'), 'the target must sit on the <select> itself, not a wrapping row');
        self::assertSame('change->admin--mail-provider#apply', $select->attr('data-action'));

        // Every provider the registry knows about — including any added later — must have
        // every one of its fields wrapped in a row carrying BOTH Stimulus attributes the
        // reveal JS reads. A provider added to the registry without this tagging renders (no
        // PHP error — row_attr is just silently absent) but is permanently stuck: it can never
        // be shown OR hidden, no matter what's selected.
        foreach ($registry->getAll() as $provider) {
            foreach ($provider->fields as $field) {
                $input = $crawler->filter('[name="form['.$field.']"]');
                self::assertGreaterThan(0, $input->count(), "field $field (provider {$provider->key}) must be rendered");

                $row = $input->closest('[data-admin--mail-provider-target="field"]');
                self::assertNotNull($row, "field $field (provider {$provider->key}) has no ancestor row tagged data-admin--mail-provider-target=\"field\" — the reveal JS will never touch it");
                self::assertSame($provider->key, $row->attr('data-mail-provider-field'), "field $field's row is tagged for the wrong provider");
            }
        }
    }

    public function testUntouchedInstallationDefaultsToSmtp(): void
    {
        // ⚠ REGRESSION GUARD: a fresh/never-saved install must default the provider select to
        // 'smtp' (the schema default), exactly the pre-existing behaviour.
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        $crawler = $client->request('GET', '/admin/settings/email');
        $form = $crawler->filter('button.btn-primary')->form();

        // A <select>'s current value lives in which <option> is selected, not a `value`
        // attribute on the element itself — read it through the Form API, not attr().
        self::assertSame('smtp', $form->get('form[mail_provider]')->getValue());
    }

    public function testProviderSwitchNeverClearsTheOtherProvidersSavedFields(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        // ⚠ static::getContainer() is only valid for the CURRENT kernel boot — the test client
        // reboots the kernel on each request(), so SettingsManager is re-fetched after every
        // request below rather than once at the top (a stale reference from a discarded boot).

        // Cycle through ALL FIVE providers, ending back on smtp — after EVERY step, re-check
        // EVERY previously-set provider's fields, not just the immediately-prior one, so a
        // regression touching an unrelated provider pair can't hide. An indexed list (not a
        // provider-keyed map) on purpose: 'smtp' appears TWICE (first and last step), and a
        // map would silently collapse the two into one, losing the "back to smtp" step.
        $steps = [
            ['smtp', ['smtp_host' => 'host1.example.test']],
            ['resend', ['resend_api_key' => 're_test_ROUND_TRIP']],
            ['mailgun', ['mailgun_api_key' => 'mg_key_1', 'mailgun_domain' => 'mg.example.test', 'mailgun_region' => 'eu']],
            ['postmark', ['postmark_server_token' => 'srv_token_1']],
            ['brevo', ['brevo_api_key' => 'brevo_key_1']],
            ['smtp', []], // back to smtp, touching nothing new — smtp_host must still be set
        ];

        $touchedSoFar = [];
        foreach ($steps as [$provider, $fields]) {
            $crawler = $client->request('GET', '/admin/settings/email');
            $form = $crawler->filter('button.btn-primary')->form();
            $form['form[mail_provider]'] = $provider;
            foreach ($fields as $key => $value) {
                $form['form['.$key.']'] = $value;
            }
            $client->submit($form);

            $settings = static::getContainer()->get(SettingsManager::class);
            self::assertSame($provider, $settings->get('mail_provider'));
            foreach ($fields as $key => $value) {
                $touchedSoFar[$key] = $value;
            }

            foreach ($touchedSoFar as $key => $expected) {
                if (str_ends_with($key, '_key') || str_ends_with($key, '_token')) {
                    self::assertTrue($settings->isEncryptedValueReadable($key), "$key must still decrypt after switching to $provider");
                } else {
                    self::assertSame($expected, $settings->get($key), "$key must survive switching to $provider");
                }
            }
        }
    }

    public function testUnrelatedSenderFieldSaveShowsNoRestartWorkerWarning(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        $crawler = $client->request('GET', '/admin/settings/email');
        $form = $crawler->filter('button.btn-primary')->form();
        $form['form[mail_from_name]'] = 'Changed Sender Name';
        $client->submit($form);
        $client->followRedirect();

        self::assertSelectorTextContains('body', 'Settings saved');
        self::assertSelectorTextNotContains('body', 'restart it now');
    }

    public function testChangingProviderShowsTheRestartWorkerWarning(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        $crawler = $client->request('GET', '/admin/settings/email');
        $form = $crawler->filter('button.btn-primary')->form();
        $form['form[mail_provider]'] = 'resend';
        $form['form[resend_api_key]'] = 're_test_WARNING_CHECK';
        $client->submit($form);
        $client->followRedirect();

        self::assertSelectorTextContains('body', 'restart it now');
    }

    private function makeAdmin(): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $email = 'mail_provider_'.bin2hex(random_bytes(6)).'@test.local';
        $user = (new User($email))->setRoles(['ROLE_ADMIN']);
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $em->persist($user);
        $em->flush();
        $this->createdEmails[] = $email;

        return $user;
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        foreach (self::TOUCHED_SETTINGS as $name) {
            if (null !== ($setting = $em->getRepository(Setting::class)->findOneBy(['name' => $name]))) {
                $em->remove($setting);
            }
        }

        $userRepo = $em->getRepository(User::class);
        foreach ($this->createdEmails as $email) {
            if (null !== ($user = $userRepo->findOneBy(['email' => $email]))) {
                $em->remove($user);
            }
        }
        $em->flush();
        $this->createdEmails = [];

        parent::tearDown();
    }
}
