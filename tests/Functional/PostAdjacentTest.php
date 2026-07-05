<?php

namespace App\Tests\Functional;

use App\Entity\Post;
use App\Repository\PostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * v1.5.0 Grupa D: chronological previous/next published-post lookups.
 * Previous = older (smaller publishedAt, id), Next = newer; published-only (drafts excluded);
 * null at the ends. Needs the migrated test DB (see AdminAccessTest).
 */
class PostAdjacentTest extends WebTestCase
{
    private PostRepository $repo;
    private EntityManagerInterface $em;
    /** @var int[] */
    private array $ids = [];

    /** @var array<string,Post> */
    private array $posts = [];

    protected function setUp(): void
    {
        static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repo = self::getContainer()->get(PostRepository::class);

        $rnd = bin2hex(random_bytes(4));
        // Three published posts oldest→newest, plus a draft dated BETWEEN p2 and p3 (must never
        // appear as a neighbour), plus a same-timestamp pair to exercise the id tiebreak.
        $mk = function (string $key, string $date, string $status) use ($rnd): Post {
            $p = (new Post('ADJ '.$key.' '.$rnd, 'adj-'.$key.'-'.$rnd))
                ->setStatus($status)
                ->setPublishedAt(new \DateTimeImmutable($date));
            $this->em->persist($p);

            return $this->posts[$key] = $p;
        };

        // Far-future dates so this cluster is ISOLATED on the timeline — no demo/other-test post
        // falls between them, so within-cluster neighbours are deterministic (the prev/next lookup
        // is global). p1..t span 2099-01→03; the global-extreme posts below make the null-at-ends
        // checks deterministic regardless of what else lives in the shared test DB.
        $mk('p1', '2099-01-01 10:00:00', Post::STATUS_PUBLISHED);
        $mk('p2', '2099-02-01 10:00:00', Post::STATUS_PUBLISHED);
        $mk('draft', '2099-02-15 10:00:00', Post::STATUS_DRAFT);
        $mk('p3', '2099-03-01 10:00:00', Post::STATUS_PUBLISHED);
        // Same timestamp as p3 → tiebreak by id (t is created after p3 → larger id → newer).
        $mk('t', '2099-03-01 10:00:00', Post::STATUS_PUBLISHED);
        // Global extremes for the null-at-ends checks: nothing published is older than 'oldest'
        // (1900) or newer than 'newest' (2100).
        $mk('oldest', '1900-01-01 10:00:00', Post::STATUS_PUBLISHED);
        $mk('newest', '2100-01-01 10:00:00', Post::STATUS_PUBLISHED);

        $this->em->flush();
        foreach ($this->posts as $p) {
            $this->ids[] = $p->getId();
        }
    }

    public function testPreviousIsTheOlderNeighbour(): void
    {
        self::assertSame($this->posts['p1']->getId(), $this->repo->findPreviousPublished($this->posts['p2'])->getId());
        // From p3, the older neighbour is p2 — the draft dated between them is skipped.
        self::assertSame($this->posts['p2']->getId(), $this->repo->findPreviousPublished($this->posts['p3'])->getId());
    }

    public function testNextIsTheNewerNeighbour(): void
    {
        self::assertSame($this->posts['p2']->getId(), $this->repo->findNextPublished($this->posts['p1'])->getId());
        // p3 and t share a timestamp; from p2 the nearest newer is p3 (smaller id of the pair).
        self::assertSame($this->posts['p3']->getId(), $this->repo->findNextPublished($this->posts['p2'])->getId());
    }

    public function testSameTimestampBreaksByIdNotByDate(): void
    {
        // p3 and t are same-instant; t was persisted later → larger id → the "next" of p3.
        self::assertSame($this->posts['t']->getId(), $this->repo->findNextPublished($this->posts['p3'])->getId());
        self::assertSame($this->posts['p3']->getId(), $this->repo->findPreviousPublished($this->posts['t'])->getId());
    }

    public function testEndsReturnNull(): void
    {
        self::assertNull($this->repo->findPreviousPublished($this->posts['oldest']), 'the globally oldest post has no previous');
        self::assertNull($this->repo->findNextPublished($this->posts['newest']), 'the globally newest post has no next');
    }

    public function testDraftIsNeverANeighbour(): void
    {
        // The draft sits (by date) between p2 and p3, yet neither side of it is a neighbour.
        self::assertNotSame($this->posts['draft']->getId(), $this->repo->findNextPublished($this->posts['p2'])->getId());
        self::assertNotSame($this->posts['draft']->getId(), $this->repo->findPreviousPublished($this->posts['p3'])->getId());
    }

    protected function tearDown(): void
    {
        if ([] !== $this->ids) {
            $conn = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
            foreach ($this->ids as $id) {
                $conn->executeStatement('DELETE FROM post WHERE id = ?', [$id]);
            }
            $this->ids = [];
        }

        parent::tearDown();
    }
}
