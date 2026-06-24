<?php

namespace App\Tests\Functional;

use App\Entity\Theme;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Theme admin: list renders, thumbnail is path-safe (200/404), activation points the active row at a
 * detected valid theme.
 */
class ThemesControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $themesDir;
    private string $testTheme = 'zztest-theme';
    private string $adminEmail;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->themesDir = self::getContainer()->getParameter('kernel.project_dir').'/themes';

        // A second, valid, inactive theme so the list shows an "Aktiviraj" form to submit.
        @mkdir($this->themesDir.'/'.$this->testTheme.'/templates', 0777, true);
        file_put_contents($this->themesDir.'/'.$this->testTheme.'/theme.yaml', "name: {$this->testTheme}\nlabel: ZZ Test\n");
        file_put_contents($this->themesDir.'/'.$this->testTheme.'/templates/layout.html.twig', '<html></html>');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->adminEmail = 'themes_'.bin2hex(random_bytes(5)).'@test.local';
        $admin = (new User($this->adminEmail))->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'x'));
        $em->persist($admin);
        $em->flush();
        $this->client->loginUser($admin);
    }

    public function testListRendersDetectedThemes(): void
    {
        $this->client->request('GET', '/admin/themes');
        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Default', $body);
        self::assertStringContainsString('ZZ Test', $body);
    }

    public function testThumbnailServedForDefaultAnd404ForMissing(): void
    {
        $this->client->request('GET', '/admin/themes/default/thumbnail');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('image/png', (string) $this->client->getResponse()->headers->get('Content-Type'));

        $this->client->request('GET', '/admin/themes/nemaovakve/thumbnail');
        self::assertResponseStatusCodeSame(404);
    }

    public function testActivateSetsActiveTheme(): void
    {
        $crawler = $this->client->request('GET', '/admin/themes');
        $form = $crawler->filter('form[action$="/'.$this->testTheme.'/activate"]')->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/themes');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $row = $em->getRepository(Theme::class)->findOneBy(['name' => $this->testTheme]);
        self::assertNotNull($row);
        self::assertTrue($row->isActive());
    }

    protected function tearDown(): void
    {
        $conn = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $conn->executeStatement('DELETE FROM theme WHERE name = ?', [$this->testTheme]);
        $conn->executeStatement('DELETE FROM user WHERE email = ?', [$this->adminEmail]);

        @unlink($this->themesDir.'/'.$this->testTheme.'/templates/layout.html.twig');
        @unlink($this->themesDir.'/'.$this->testTheme.'/theme.yaml');
        @rmdir($this->themesDir.'/'.$this->testTheme.'/templates');
        @rmdir($this->themesDir.'/'.$this->testTheme);

        parent::tearDown();
    }
}
