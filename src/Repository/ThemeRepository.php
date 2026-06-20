<?php

namespace App\Repository;

use App\Entity\Theme;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Theme>
 */
class ThemeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Theme::class);
    }

    public function findActive(): ?Theme
    {
        // Deterministic even if data somehow holds more than one active theme:
        // always resolve to the lowest id. (CRUD also enforces a single active.)
        return $this->findOneBy(['active' => true], ['id' => 'ASC']);
    }
}
