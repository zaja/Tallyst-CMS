<?php

namespace App\Tests\Functional;

use App\Entity\Setting;
use App\Entity\User;
use App\Settings\SettingsManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A1 routing skeleton: Settings tabs are routed pages, each rendering only its own fields.
 * Locks the three A1 guarantees:
 *  - saving ONE tab (a form subset) never wipes ANOTHER tab's secrets (Stripe/SMTP),
 *  - after save you stay on the SAME tab (not the first),
 *  - an unknown tab is a 404 (only registered sections are tabs — the fallback creates a
 *    tab per section, never for an arbitrary path).
 *
 * Needs the migrated test DB (see AdminAccessTest).
 */
class SettingsTabTest extends WebTestCase
{
    /** @var string[] */
    private array $createdEmails = [];

    /**
     * Setting names this test may write (seeded secrets + every key the General and Email tab
     * saves persist), removed in tearDown so the shared test DB returns to unset defaults.
     */
    private const TOUCHED_SETTINGS = [
        'stripe_secret_key', 'smtp_password',
        // General tab (site_name/tagline/search + blog + localization + maintenance).
        'site_name', 'site_tagline', 'search_enabled', 'blog_posts_per_page',
        'app_locale', 'app_timezone', 'app_date_format',
        'maintenance_enabled', 'maintenance_message',
        // Email tab.
        'mail_from_name', 'mail_from_email', 'mail_reply_to', 'order_admin_email',
        'smtp_host', 'smtp_port', 'smtp_username', 'smtp_encryption',
    ];

    public function testSavingOneTabDoesNotWipeAnotherTabsSecrets(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        $settings = static::getContainer()->get(SettingsManager::class);
        // Seed secrets that live on OTHER tabs (Stripe, Email) — encrypted at rest.
        $settings->set('stripe_secret_key', 'sk_test_KEEP_ME');
        $settings->set('smtp_password', 'smtp_KEEP_ME');
        self::assertTrue($settings->isEncryptedValueReadable('stripe_secret_key'));

        // Save the GENERAL tab only (its form carries site_name/site_tagline/search_enabled,
        // NOT the secrets).
        $crawler = $client->request('GET', '/admin/settings/general');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('button.btn-primary')->form();
        $form['form[site_name]'] = 'Changed Site Name';
        $client->submit($form);

        // Stays on the SAME tab after save (not the first tab — same here, but the redirect
        // target is the submitted tab, which is the point).
        self::assertResponseRedirects('/admin/settings/general');

        // The other tabs' secrets survive the partial save.
        self::assertSame('sk_test_KEEP_ME', $settings->get('stripe_secret_key'), 'Stripe secret must survive a General-tab save');
        self::assertSame('smtp_KEEP_ME', $settings->get('smtp_password'), 'SMTP password must survive a General-tab save');
        // And the general field we changed did persist.
        self::assertSame('Changed Site Name', $settings->get('site_name'));
    }

    public function testSaveStaysOnTheSubmittedTab(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        // Email is a standalone tab that is NOT first — proves the redirect follows the
        // submitted tab, not loop.first.
        $crawler = $client->request('GET', '/admin/settings/email');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('button.btn-primary')->form();
        $client->submit($form);

        self::assertResponseRedirects('/admin/settings/email');
    }

    public function testGeneralTabConsolidatesFourSubsections(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        $crawler = $client->request('GET', '/admin/settings/general');
        self::assertResponseIsSuccessful();

        // One field from each of the four merged sections is present in the single form.
        foreach (['site_name', 'blog_posts_per_page', 'app_locale', 'maintenance_enabled'] as $field) {
            self::assertGreaterThan(0, $crawler->filter('[name="form['.$field.']"]')->count(), "field $field must render in the General tab");
        }
        // Each sub-section is anchor-linkable (id = section key).
        foreach (['general', 'blog', 'localization', 'maintenance'] as $anchor) {
            self::assertGreaterThan(0, $crawler->filter('#'.$anchor)->count(), "anchor #$anchor must exist");
        }
    }

    public function testBrandingTabIncludesTypography(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        $crawler = $client->request('GET', '/admin/settings/branding');
        self::assertResponseIsSuccessful();

        self::assertGreaterThan(0, $crawler->filter('[name="form[logo_media_id]"]')->count());
        self::assertGreaterThan(0, $crawler->filter('[name="form[display_font]"]')->count(), 'typography field folded into Branding');
        self::assertGreaterThan(0, $crawler->filter('#typography')->count());
    }

    public function testHeaderFooterTabMergesTopBarAndFooter(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        $crawler = $client->request('GET', '/admin/settings/header_footer');
        self::assertResponseIsSuccessful();

        self::assertGreaterThan(0, $crawler->filter('[name="form[top_bar_enabled]"]')->count());
        self::assertGreaterThan(0, $crawler->filter('[name="form[footer_columns]"]')->count());
        self::assertGreaterThan(0, $crawler->filter('#topbar')->count());
        self::assertGreaterThan(0, $crawler->filter('#footer')->count());
    }

    public function testConsolidatedSubsectionKeysAreNotStandaloneTabs(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        // These section keys are now sub-sections of grouped tabs — no longer their own tab.
        foreach (['blog', 'localization', 'maintenance', 'typography', 'topbar', 'footer'] as $merged) {
            $client->request('GET', '/admin/settings/'.$merged);
            self::assertResponseStatusCodeSame(404, "$merged is a sub-section now, not a standalone tab");
        }
    }

    public function testBareSettingsRedirectsToTheFirstTab(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        $client->request('GET', '/admin/settings');
        // The admin_settings route stays alive as a redirect to the first tab (general).
        self::assertResponseRedirects('/admin/settings/general');
    }

    public function testUnknownTabIs404(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        $client->request('GET', '/admin/settings/does-not-exist');
        self::assertResponseStatusCodeSame(404, 'only registered sections are tabs — no catch-all tab');
    }

    private function makeAdmin(): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $email = 'settings_tab_'.bin2hex(random_bytes(6)).'@test.local';
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
