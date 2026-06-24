<?php

namespace App\Repository;

use App\Entity\Category;
use App\Entity\Post;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Post>
 */
class PostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Post::class);
    }

    public function findPublishedBySlug(string $slug): ?Post
    {
        return $this->findOneBy(['slug' => $slug, 'status' => Post::STATUS_PUBLISHED]);
    }

    /** @return Post[] */
    public function findPublished(?Category $category = null): array
    {
        $criteria = ['status' => Post::STATUS_PUBLISHED];
        if (null !== $category) {
            $criteria['category'] = $category;
        }

        return $this->findBy($criteria, ['publishedAt' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * Most recent posts (any status — admins want recent drafts too) for the dashboard list.
     *
     * @return Post[]
     */
    public function recent(int $limit = 10): array
    {
        return $this->findBy([], ['id' => 'DESC'], $limit);
    }

    /**
     * FULLTEXT search over published posts, title weighted ×2 above excerpt+content. Native SQL.
     *
     * @return list<array{id:int, title:string, slug:string, excerpt:?string, content:?string, score:float}>
     */
    public function searchPublished(string $boolean, int $limit): array
    {
        $sql = 'SELECT id, title, slug, excerpt, content,
                       (MATCH(title) AGAINST(? IN BOOLEAN MODE) * 2 + MATCH(excerpt, content) AGAINST(? IN BOOLEAN MODE)) AS score
                FROM post
                WHERE status = ?
                  AND (MATCH(title) AGAINST(? IN BOOLEAN MODE) OR MATCH(excerpt, content) AGAINST(? IN BOOLEAN MODE))
                ORDER BY score DESC
                LIMIT '.(int) $limit;

        return $this->getEntityManager()->getConnection()->executeQuery(
            $sql,
            [$boolean, $boolean, Post::STATUS_PUBLISHED, $boolean, $boolean],
        )->fetchAllAssociative();
    }

    /**
     * Published posts for one page, newest first, optionally scoped to a category. Wrapped in a
     * Doctrine Paginator so count() is correct alongside the LIMIT/OFFSET slice.
     *
     * - `id` is a stable tiebreaker so items don't shift between pages.
     * - category + featuredImage are to-ONE, so we join-fetch them (no N+1 in the card loop)
     *   AND pass `fetchJoinCollection: false` — that flag is only about to-MANY fetch joins, which
     *   we deliberately avoid (they'd break the count / row-multiply with LIMIT).
     *
     * @return Paginator<Post>
     */
    public function paginatePublished(?Category $category, int $offset, int $limit): Paginator
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.category', 'c')->addSelect('c')
            ->leftJoin('p.featuredImage', 'fi')->addSelect('fi')
            ->where('p.status = :status')->setParameter('status', Post::STATUS_PUBLISHED)
            ->orderBy('p.publishedAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        if (null !== $category) {
            $qb->andWhere('p.category = :category')->setParameter('category', $category);
        }

        return new Paginator($qb, fetchJoinCollection: false);
    }
}
