<?php

namespace App\Repository;

use App\Entity\Category;
use App\Entity\Post;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    public function findOneBySlug(string $slug): ?Category
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * Categories that have at least one published post — i.e. non-empty archive pages (for the sitemap;
     * empty archives are thin content and excluded).
     *
     * @return Category[]
     */
    public function findWithPublishedPosts(): array
    {
        return $this->createQueryBuilder('c')
            ->innerJoin(Post::class, 'p', 'WITH', 'p.category = c')
            ->where('p.status = :pub')
            ->setParameter('pub', Post::STATUS_PUBLISHED)
            ->distinct()
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * FULLTEXT search over categories (all are public), name weighted ×2 above description. Native SQL.
     *
     * @return list<array{id:int, name:string, slug:string, description:?string, score:float}>
     */
    public function search(string $boolean, int $limit): array
    {
        $sql = 'SELECT id, name, slug, description,
                       (MATCH(name) AGAINST(? IN BOOLEAN MODE) * 2 + MATCH(description) AGAINST(? IN BOOLEAN MODE)) AS score
                FROM category
                WHERE MATCH(name) AGAINST(? IN BOOLEAN MODE) OR MATCH(description) AGAINST(? IN BOOLEAN MODE)
                ORDER BY score DESC
                LIMIT '.(int) $limit;

        return $this->getEntityManager()->getConnection()->executeQuery(
            $sql,
            [$boolean, $boolean, $boolean, $boolean],
        )->fetchAllAssociative();
    }
}
