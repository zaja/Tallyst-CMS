<?php

namespace App\Repository;

use App\Entity\Category;
use App\Entity\Post;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
}
