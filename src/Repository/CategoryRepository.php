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
}
