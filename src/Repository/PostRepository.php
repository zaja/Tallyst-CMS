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

    /**
     * The chronologically PREVIOUS (older) published post, or null if $cur is the oldest. Ordered
     * by the same compound key as the blog list (publishedAt, id) so ties break by id. Older =
     * a smaller (publishedAt, id). featuredImage is join-fetched so the nav thumbnail is no N+1.
     * A $cur with a null publishedAt matches nothing (pragmatic — a published post without a date
     * simply has no neighbours; see CLAUDE.md).
     */
    public function findPreviousPublished(Post $cur): ?Post
    {
        return $this->adjacentQuery($cur, '<', 'DESC')->getQuery()->getOneOrNullResult();
    }

    /** The chronologically NEXT (newer) published post, or null if $cur is the newest. */
    public function findNextPublished(Post $cur): ?Post
    {
        return $this->adjacentQuery($cur, '>', 'ASC')->getQuery()->getOneOrNullResult();
    }

    /**
     * Neighbour query on the (publishedAt, id) compound key: strictly on one side of $cur's
     * publishedAt, breaking ties by id on the same side. $direction orders the scan so the FIRST
     * row (LIMIT 1) is the nearest neighbour. Published-only, same filter as the blog list.
     */
    private function adjacentQuery(Post $cur, string $op, string $direction): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.featuredImage', 'fi')->addSelect('fi')
            ->where('p.status = :status')->setParameter('status', Post::STATUS_PUBLISHED)
            // Outer parens are REQUIRED: without them `status = X AND A OR B` binds as
            // `(status = X AND A) OR B`, so the tie branch would drop the published filter.
            ->andWhere(sprintf('(p.publishedAt %1$s :pub OR (p.publishedAt = :pub AND p.id %1$s :id))', $op))
            ->setParameter('pub', $cur->getPublishedAt())
            ->setParameter('id', $cur->getId())
            ->orderBy('p.publishedAt', $direction)
            ->addOrderBy('p.id', $direction)
            ->setMaxResults(1);
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
