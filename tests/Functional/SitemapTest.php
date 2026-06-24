<?php

namespace App\Tests\Functional;

use App\Entity\Category;
use App\Entity\Page;
use App\Entity\Post;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * /sitemap.xml lists ONLY published public content as absolute URLs (home once as "/", never "/home"),
 * excludes drafts; /robots.txt references the sitemap and disallows /admin.
 */
class SitemapTest extends WebTestCase
{
    private string $base;
    private string $pubSlug;
    private string $draftSlug;
    private string $postSlug;
    private string $catSlug;
    private array $ids = ['post' => null, 'cat' => null, 'pagePub' => null, 'pageDraft' => null, 'home' => null];
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->base = rtrim((string) (getenv('DEFAULT_URI') ?: 'http://localhost'), '/');
        $rnd = bin2hex(random_bytes(4));
        $this->pubSlug = 'sitemap-page-pub-'.$rnd;
        $this->draftSlug = 'sitemap-page-draft-'.$rnd;
        $this->postSlug = 'sitemap-post-'.$rnd;
        $this->catSlug = 'sitemap-cat-'.$rnd;

        $em = self::getContainer()->get(EntityManagerInterface::class);

        $pub = (new Page('Objavljena', $this->pubSlug))->setStatus(Page::STATUS_PUBLISHED);
        $draft = (new Page('Skica', $this->draftSlug))->setStatus(Page::STATUS_DRAFT);
        $cat = new Category('Vijesti '.$rnd, $this->catSlug);
        $em->persist($pub);
        $em->persist($draft);
        $em->persist($cat);
        $em->flush();

        $post = (new Post('Objava', $this->postSlug))
            ->setStatus(Post::STATUS_PUBLISHED)
            ->setCategory($cat)
            ->setPublishedAt(new \DateTimeImmutable('2026-05-20'));
        $em->persist($post);

        // Create a published "home" page only if none exists, so the de-dup assertion is meaningful.
        if (null === $em->getRepository(Page::class)->findOneBy(['slug' => 'home'])) {
            $home = (new Page('Naslovnica', 'home'))->setStatus(Page::STATUS_PUBLISHED);
            $em->persist($home);
            $em->flush();
            $this->ids['home'] = $home->getId();
        }
        $em->flush();

        $this->ids['pagePub'] = $pub->getId();
        $this->ids['pageDraft'] = $draft->getId();
        $this->ids['cat'] = $cat->getId();
        $this->ids['post'] = $post->getId();
    }

    public function testSitemapListsPublishedContentAsAbsoluteUrls(): void
    {
        
        $this->client->request('GET', '/sitemap.xml');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('xml', (string) $this->client->getResponse()->headers->get('Content-Type'));

        $body = (string) $this->client->getResponse()->getContent();

        // Well-formed XML.
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($body), 'sitemap is well-formed XML');

        // Home emitted once as "/", NOT as "/home" (de-dup).
        self::assertStringContainsString('<loc>'.$this->base.'/</loc>', $body);
        self::assertStringNotContainsString('<loc>'.$this->base.'/home</loc>', $body);

        // Published page + post (with lastmod) + category present, absolute.
        self::assertStringContainsString('<loc>'.$this->base.'/'.$this->pubSlug.'</loc>', $body);
        self::assertStringContainsString('<loc>'.$this->base.'/blog/'.$this->postSlug.'</loc>', $body);
        self::assertStringContainsString('<lastmod>2026-05-20</lastmod>', $body);
        self::assertStringContainsString('<loc>'.$this->base.'/kategorija/'.$this->catSlug.'</loc>', $body);

        // Draft excluded.
        self::assertStringNotContainsString($this->draftSlug, $body);
    }

    public function testRobotsReferencesSitemapAndDisallowsAdmin(): void
    {
        
        $this->client->request('GET', '/robots.txt');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/plain', (string) $this->client->getResponse()->headers->get('Content-Type'));

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Disallow: /admin', $body);
        self::assertStringContainsString('Sitemap: '.$this->base.'/sitemap.xml', $body);
    }

    protected function tearDown(): void
    {
        $conn = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        if ($this->ids['post']) {
            $conn->executeStatement('DELETE FROM post WHERE id = ?', [$this->ids['post']]);
        }
        if ($this->ids['cat']) {
            $conn->executeStatement('DELETE FROM category WHERE id = ?', [$this->ids['cat']]);
        }
        foreach (['pagePub', 'pageDraft', 'home'] as $k) {
            if ($this->ids[$k]) {
                $conn->executeStatement('DELETE FROM page WHERE id = ?', [$this->ids[$k]]);
            }
        }
        parent::tearDown();
    }
}
