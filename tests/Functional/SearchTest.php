<?php

namespace App\Tests\Functional;

use App\Entity\Category;
use App\Entity\Page;
use App\Entity\Post;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FULLTEXT search returns published matches only (no drafts), ranks title hits above body hits, handles
 * short/empty queries gracefully, and escapes the echoed query (XSS).
 */
class SearchTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $term;
    /** @var array<string,int> */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->term = 'kviz'.bin2hex(random_bytes(3)); // unique, ≥3 chars, alnum → indexable
        $rnd = bin2hex(random_bytes(3));
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $pageTitle = (new Page('TITLEHIT '.$this->term, 'srch-pt-'.$rnd))
            ->setStatus(Page::STATUS_PUBLISHED)->setContent('Sadržaj bez pojma.');
        $pageContent = (new Page('CONTENTHIT obicna', 'srch-pc-'.$rnd))
            ->setStatus(Page::STATUS_PUBLISHED)->setContent('Tekst koji spominje '.$this->term.' u sadržaju.');
        $draft = (new Page('DRAFTHIT '.$this->term, 'srch-d-'.$rnd))
            ->setStatus(Page::STATUS_DRAFT)->setContent($this->term);
        $post = (new Post('POSTHIT '.$this->term, 'srch-po-'.$rnd))
            ->setStatus(Post::STATUS_PUBLISHED)->setContent('objava')->setPublishedAt(new \DateTimeImmutable('2026-05-01'));
        $cat = new Category('CATHIT '.$this->term, 'srch-cat-'.$rnd);

        foreach (['pt' => $pageTitle, 'pc' => $pageContent, 'd' => $draft, 'po' => $post, 'cat' => $cat] as $k => $e) {
            $em->persist($e);
        }
        $em->flush();

        $this->ids = [
            'pt' => $pageTitle->getId(), 'pc' => $pageContent->getId(), 'd' => $draft->getId(),
            'po' => $post->getId(), 'cat' => $cat->getId(),
        ];
    }

    public function testRanksPublishedMatchesTitleAboveBodyAndExcludesDrafts(): void
    {
        $this->client->request('GET', '/pretraga', ['q' => $this->term]);
        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('TITLEHIT', $body, 'page matched by title');
        self::assertStringContainsString('CONTENTHIT', $body, 'page matched by content');
        self::assertStringContainsString('POSTHIT', $body, 'published post matched');
        self::assertStringContainsString('CATHIT', $body, 'category matched');
        self::assertStringNotContainsString('DRAFTHIT', $body, 'draft is NOT searchable');

        // Title match outranks body-only match.
        self::assertLessThan(strpos($body, 'CONTENTHIT'), strpos($body, 'TITLEHIT'), 'title hit ranked above content hit');

        // Type badges present.
        self::assertStringContainsString('Stranica', $body);
        self::assertStringContainsString('Objava', $body);
        self::assertStringContainsString('Kategorija', $body);
    }

    public function testShortQueryIsGraceful(): void
    {
        $this->client->request('GET', '/pretraga', ['q' => 'tv']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Type at least 3 characters', (string) $this->client->getResponse()->getContent());
    }

    public function testNoResultsMessage(): void
    {
        $this->client->request('GET', '/pretraga', ['q' => 'nepostojeci'.bin2hex(random_bytes(4))]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No results', (string) $this->client->getResponse()->getContent());
    }

    public function testQueryIsEscapedAgainstXss(): void
    {
        $this->client->request('GET', '/pretraga', ['q' => '<script>kvizxss</script>']);
        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('<script>kvizxss', $body, 'injected script must be escaped');
        self::assertStringContainsString('kvizxss', $body, 'the (escaped) query is echoed back');
    }

    protected function tearDown(): void
    {
        $conn = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $conn->executeStatement('DELETE FROM post WHERE id = ?', [$this->ids['po']]);
        $conn->executeStatement('DELETE FROM category WHERE id = ?', [$this->ids['cat']]);
        foreach (['pt', 'pc', 'd'] as $k) {
            $conn->executeStatement('DELETE FROM page WHERE id = ?', [$this->ids[$k]]);
        }
        parent::tearDown();
    }
}
