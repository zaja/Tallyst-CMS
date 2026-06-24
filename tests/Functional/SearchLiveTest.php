<?php

namespace App\Tests\Functional;

use App\Entity\Page;
use App\Settings\SettingsManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Live-search JSON endpoint: reuses SearchService (top 5), published-only, gated on search_enabled.
 */
class SearchLiveTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $term;
    /** @var int[] */
    private array $pageIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->term = 'kvizlive'.bin2hex(random_bytes(3));
        $rnd = bin2hex(random_bytes(3));

        self::getContainer()->get(SettingsManager::class)->set('search_enabled', true);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        // 6 published matches (to prove the 5-cap) + 1 draft (must be excluded).
        for ($i = 1; $i <= 6; ++$i) {
            $p = (new Page("Live {$i} ".$this->term, "live-{$rnd}-{$i}"))
                ->setStatus(Page::STATUS_PUBLISHED)->setContent('sadržaj');
            $em->persist($p);
            $em->flush();
            $this->pageIds[] = $p->getId();
        }
        $draft = (new Page('DRAFTLIVE '.$this->term, 'live-draft-'.$rnd))
            ->setStatus(Page::STATUS_DRAFT)->setContent('x');
        $em->persist($draft);
        $em->flush();
        $this->pageIds[] = $draft->getId();
    }

    public function testLiveReturnsTopFivePublishedJson(): void
    {
        $this->client->request('GET', '/pretraga/live', ['q' => $this->term]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('application/json', (string) $this->client->getResponse()->headers->get('Content-Type'));

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data['results']);
        self::assertCount(5, $data['results'], 'capped at 5');

        $titles = array_column($data['results'], 'title');
        self::assertNotContains('DRAFTLIVE '.$this->term, $titles, 'draft excluded');
        foreach ($data['results'] as $r) {
            self::assertArrayHasKey('title', $r);
            self::assertArrayHasKey('type', $r);
            self::assertArrayHasKey('url', $r);
            self::assertArrayHasKey('snippet', $r);
            self::assertIsString($r['snippet']);
        }
    }

    public function testXssQueryReturnsValidJson(): void
    {
        $this->client->request('GET', '/pretraga/live', ['q' => '<script>kvizxss</script>']);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data['results']);
    }

    public function testShortQueryReturnsEmpty(): void
    {
        $this->client->request('GET', '/pretraga/live', ['q' => 'tv']);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame([], $data['results']);
    }

    public function testDisabledToggleReturnsEmpty(): void
    {
        self::getContainer()->get(SettingsManager::class)->set('search_enabled', false);

        $this->client->request('GET', '/pretraga/live', ['q' => $this->term]);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame([], $data['results']);
    }

    protected function tearDown(): void
    {
        $conn = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        foreach ($this->pageIds as $id) {
            $conn->executeStatement('DELETE FROM page WHERE id = ?', [$id]);
        }
        $conn->executeStatement('DELETE FROM setting WHERE name = ?', ['search_enabled']);
        parent::tearDown();
    }
}
