<?php

namespace App\Tests\Functional;

use App\Entity\Setting;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * v1.5.0 Grupa C: the admin sidebar demo-link toggle (hide_demo_link) and the null-safe admin
 * branding in configureDashboard() — an un-branded install must render the "Tallyst CMS" text
 * title + the default favicon, never crash on unset/empty Media-id settings.
 *
 * Needs the migrated test DB (see AdminAccessTest).
 */
class AdminBrandingTest extends WebTestCase
{
    /** EA renders route menu items as /admin?routeName=<name> (query-param scheme). */
    private const DEMO_LINK = 'a[href*="routeName=admin_demo"]';

    private ?string $createdEmail = null;

    public function testDemoLinkVisibleByDefaultAndHiddenWhenSet(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $client->getCrawler()->filter(self::DEMO_LINK)->count(), 'demo link shown by default (hide_demo_link unset)');

        $this->setSetting('hide_demo_link', '1');

        $client->request('GET', '/admin');
        self::assertSame(0, $client->getCrawler()->filter(self::DEMO_LINK)->count(), 'demo link hidden when hide_demo_link is on');
    }

    public function testUnbrandedDashboardFallsBackToTextAndDefaultFavicon(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        // No admin_logo_media_id / admin_favicon_media_id set → text title + favicon.ico, no crash.
        $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Tallyst CMS', $html, 'title falls back to the brand text when no admin logo is set');
        self::assertStringContainsString('favicon.ico', $html, 'favicon falls back to the default when no admin favicon is set');
    }

    private function makeAdmin(): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $email = 'admin_brand_'.bin2hex(random_bytes(6)).'@test.local';
        $user = (new User($email))->setRoles(['ROLE_ADMIN']);
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $em->persist($user);
        $em->flush();
        $this->createdEmail = $email;

        return $user;
    }

    private function setSetting(string $name, string $value): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $setting = $em->getRepository(Setting::class)->findOneBy(['name' => $name]) ?? new Setting($name);
        $setting->setName($name)->setValue($value);
        $em->persist($setting);
        $em->flush();
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        if (null !== ($s = $em->getRepository(Setting::class)->findOneBy(['name' => 'hide_demo_link']))) {
            $em->remove($s);
        }
        if (null !== $this->createdEmail && null !== ($u = $em->getRepository(User::class)->findOneBy(['email' => $this->createdEmail]))) {
            $em->remove($u);
        }
        $em->flush();
        $this->createdEmail = null;

        parent::tearDown();
    }
}
