<?php

namespace App\Repository;

use App\Entity\Page;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Page>
 */
class PageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Page::class);
    }

    public function findPublishedBySlug(string $slug): ?Page
    {
        return $this->findOneBy(['slug' => $slug, 'status' => Page::STATUS_PUBLISHED]);
    }

    /** @return Page[] */
    public function findPublished(): array
    {
        return $this->findBy(
            ['status' => Page::STATUS_PUBLISHED],
            ['position' => 'ASC', 'title' => 'ASC'],
        );
    }

    /**
     * FULLTEXT search over published pages, title weighted ×2 above content. Native SQL (no DQL MATCH);
     * `$boolean` is a pre-sanitised BOOLEAN-MODE string (positional params; $limit is a trusted int).
     *
     * @return list<array{id:int, title:string, slug:string, content:?string, score:float}>
     */
    public function searchPublished(string $boolean, int $limit): array
    {
        $sql = 'SELECT id, title, slug, content,
                       (MATCH(title) AGAINST(? IN BOOLEAN MODE) * 2 + MATCH(content) AGAINST(? IN BOOLEAN MODE)) AS score
                FROM page
                WHERE status = ?
                  AND (MATCH(title) AGAINST(? IN BOOLEAN MODE) OR MATCH(content) AGAINST(? IN BOOLEAN MODE))
                ORDER BY score DESC
                LIMIT '.(int) $limit;

        return $this->getEntityManager()->getConnection()->executeQuery(
            $sql,
            [$boolean, $boolean, Page::STATUS_PUBLISHED, $boolean, $boolean],
        )->fetchAllAssociative();
    }
}
