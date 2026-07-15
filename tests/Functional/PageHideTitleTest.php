<?php

namespace App\Tests\Functional;

use App\Entity\Page;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Page.hideTitle suppresses the VISUAL <h1> page title on the front (for landing pages that
 * build their own heading in the content), while the title is still used for the browser
 * <title> / SEO. Default (false) leaves the title visible, so existing pages are unaffected.
 */
class PageHideTitleTest extends WebTestCase
{
    /** @var int[] Pages created by this test — deleted in tearDown so the test DB doesn't accumulate. */
    private array $pageIds = [];

    protected function tearDown(): void
    {
        /** @var Connection $conn */
        $conn = static::getContainer()->get(Connection::class);
        foreach ($this->pageIds as $id) {
            $conn->executeStatement('DELETE FROM page WHERE id = ?', [$id]);
        }
        $this->pageIds = [];
        parent::tearDown();
    }

    public function testHiddenTitleDropsTheH1ButKeepsTheDocumentTitle(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $rnd = bin2hex(random_bytes(4));
        $title = 'Tajni Naslov '.$rnd;
        $slug = 'hide-title-'.$rnd;

        $page = (new Page($title, $slug))
            ->setStatus(Page::STATUS_PUBLISHED)
            ->setHideTitle(true)
            ->setContent('<h1>Vlastiti naslov u sadržaju</h1><p>Landing tekst.</p>');
        $em->persist($page);
        $em->flush();
        $this->pageIds[] = $page->getId();

        $client->request('GET', '/'.$slug);
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        // The auto page-header <h1> with the page title is gone...
        self::assertStringNotContainsString('<h1>'.$title.'</h1>', $html, 'hidden title removes the page-header h1');
        // ...but the title still drives the document <title> (SEO / browser tab).
        self::assertStringContainsString('<title>', $html);
        self::assertStringContainsString($title, $html, 'title still present for <title>/SEO');
    }

    public function testVisibleTitleByDefaultRendersTheH1(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $rnd = bin2hex(random_bytes(4));
        $title = 'Vidljivi Naslov '.$rnd;
        $slug = 'show-title-'.$rnd;

        $page = (new Page($title, $slug))
            ->setStatus(Page::STATUS_PUBLISHED)
            ->setContent('<p>Obican sadrzaj.</p>');
        $em->persist($page);
        $em->flush();
        $this->pageIds[] = $page->getId();

        $client->request('GET', '/'.$slug);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('<h1>'.$title.'</h1>', (string) $client->getResponse()->getContent(),
            'default (hideTitle=false) keeps the page-header h1');
    }
}
