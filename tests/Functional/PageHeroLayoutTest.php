<?php

namespace App\Tests\Functional;

use App\Entity\Page;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Hero overlay layout fields (Page.heroPosition / heroStyle). Korak 1 = data + CRUD only:
 * the defaults must preserve current behaviour (left / photo), and the CRUD form must expose
 * the two choices. The front markup/CSS is unchanged in this step (Korak 2).
 */
class PageHeroLayoutTest extends WebTestCase
{
    /** @var int[] Pages created by this test — deleted in tearDown so the test DB doesn't accumulate. */
    private array $pageIds = [];
    /** @var int[] Users created by this test. */
    private array $userIds = [];

    protected function tearDown(): void
    {
        /** @var Connection $conn */
        $conn = static::getContainer()->get(Connection::class);
        foreach ($this->pageIds as $id) {
            $conn->executeStatement('DELETE FROM page WHERE id = ?', [$id]);
        }
        foreach ($this->userIds as $id) {
            $conn->executeStatement('DELETE FROM `user` WHERE id = ?', [$id]);
        }
        $this->pageIds = $this->userIds = [];
        parent::tearDown();
    }

    public function testDefaultsPreserveCurrentBehaviour(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $slug = 'hero-layout-default-'.bin2hex(random_bytes(4));
        $page = (new Page('Hero default', $slug))->setStatus(Page::STATUS_PUBLISHED);
        $em->persist($page);
        $em->flush();
        $id = $page->getId();
        $this->pageIds[] = $id;
        $em->clear();

        $reloaded = $em->getRepository(Page::class)->find($id);
        self::assertSame('left', $reloaded->getHeroPosition(), 'new page defaults to left (current behaviour)');
        self::assertSame('photo', $reloaded->getHeroStyle(), 'new page defaults to photo (current behaviour)');
    }

    public function testSettersPersistTheChosenValues(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $slug = 'hero-layout-set-'.bin2hex(random_bytes(4));
        $page = (new Page('Hero set', $slug))
            ->setStatus(Page::STATUS_PUBLISHED)
            ->setHeroPosition('right')
            ->setHeroStyle('dark');
        $em->persist($page);
        $em->flush();
        $id = $page->getId();
        $this->pageIds[] = $id;
        $em->clear();

        $reloaded = $em->getRepository(Page::class)->find($id);
        self::assertSame('right', $reloaded->getHeroPosition());
        self::assertSame('dark', $reloaded->getHeroStyle());
    }

    public function testOverlayHeroEmitsPositionAndStyleClasses(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $slug = 'hero-overlay-'.bin2hex(random_bytes(4));
        $page = (new Page('Hero overlay', $slug))
            ->setStatus(Page::STATUS_PUBLISHED)
            ->setHeroEnabled(true)
            ->setHeroTitle('Naslov u herou')
            ->setHeroPosition('right')
            ->setHeroStyle('light');
        // No heroImage → overlay still renders (text present); page-hero--no-image.
        $em->persist($page);
        $em->flush();
        $this->pageIds[] = $page->getId();

        $client->request('GET', '/'.$slug);
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('page-hero--right', $html, 'position class emitted');
        self::assertStringContainsString('page-hero--light', $html, 'style class emitted');
        self::assertStringContainsString('page-hero-inner', $html, 'text-inner wrapper emitted');
        self::assertStringContainsString('page-hero--no-image', $html, 'no-image modifier when image absent');
        self::assertStringNotContainsString('has-image', $html, 'dead has-image class removed');
    }

    public function testCrudFormExposesHeroLayoutChoices(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $admin = (new User('hero_layout_'.bin2hex(random_bytes(6)).'@test.local'))->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'password123'));
        $em->persist($admin);
        $em->flush();
        $this->userIds[] = $admin->getId();

        $client->loginUser($admin);
        $client->request('GET', '/admin/page/new');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        // The two new choice fields render (collapsed hero fieldset still emits them in the DOM).
        self::assertStringContainsString('Page[heroPosition]', $html, 'heroPosition select present');
        self::assertStringContainsString('Page[heroStyle]', $html, 'heroStyle select present');
        self::assertStringContainsString('value="right"', $html, 'position option right present');
        self::assertStringContainsString('value="dark"', $html, 'style option dark present');
    }
}
