<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Settings\SettingsManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Maintenance mode: public → 503; admin/login + webhooks + sitemap/robots stay reachable (no lockout);
 * logged-in admin previews the live site; OFF → normal.
 */
class MaintenanceTest extends WebTestCase
{
    private const MARKER = 'ODRZAVANJE-MARKER-XYZ';

    private KernelBrowser $client;
    private array $emails = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $s = self::getContainer()->get(SettingsManager::class);
        $s->set('maintenance_enabled', true);
        $s->set('maintenance_message', '<p>'.self::MARKER.'</p>');
    }

    public function testPublicGets503WithMessageAndRetryAfter(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseStatusCodeSame(503);
        self::assertTrue($this->client->getResponse()->headers->has('Retry-After'));
        self::assertStringContainsString(self::MARKER, (string) $this->client->getResponse()->getContent());
    }

    public function testAdminLoginNotBlocked(): void
    {
        $this->client->request('GET', '/admin/login');
        self::assertNotSame(503, $this->client->getResponse()->getStatusCode(), 'login must stay reachable');
    }

    public function testSitemapAndRobotsExempt(): void
    {
        $this->client->request('GET', '/sitemap.xml');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/robots.txt');
        self::assertResponseIsSuccessful();
    }

    public function testWebhookExempt(): void
    {
        $this->client->request('GET', '/webhook/stripe');
        self::assertNotSame(503, $this->client->getResponse()->getStatusCode(), 'webhooks must stay reachable');
    }

    public function testLoggedInAdminBypasses(): void
    {
        $this->client->loginUser($this->makeAdmin());
        $this->client->request('GET', '/');
        self::assertNotSame(503, $this->client->getResponse()->getStatusCode(), 'admin previews the live site');
    }

    public function testOffReturnsNormalSite(): void
    {
        self::getContainer()->get(SettingsManager::class)->set('maintenance_enabled', false);
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
    }

    public function testAdminSeesBannerWhenOn(): void
    {
        $this->client->loginUser($this->makeAdmin());
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Maintenance mode je aktivan', (string) $this->client->getResponse()->getContent());
    }

    public function testAdminNoBannerWhenOff(): void
    {
        self::getContainer()->get(SettingsManager::class)->set('maintenance_enabled', false);
        $this->client->loginUser($this->makeAdmin());
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Maintenance mode je aktivan', (string) $this->client->getResponse()->getContent());
    }

    private function makeAdmin(): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $email = 'maint_'.bin2hex(random_bytes(5)).'@test.local';
        $u = (new User($email))->setRoles(['ROLE_ADMIN']);
        $u->setPassword($hasher->hashPassword($u, 'x'));
        $em->persist($u);
        $em->flush();
        $this->emails[] = $email;

        return $u;
    }

    protected function tearDown(): void
    {
        // CRITICAL: clear the toggle so other test classes' public requests aren't 503'd.
        $conn = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $conn->executeStatement("DELETE FROM setting WHERE name IN ('maintenance_enabled', 'maintenance_message')");
        foreach ($this->emails as $email) {
            $conn->executeStatement('DELETE FROM user WHERE email = ?', [$email]);
        }
        parent::tearDown();
    }
}
