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
}
