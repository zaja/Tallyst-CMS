<?php

namespace Tallyst\Media\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Tallyst\Media\Entity\Media;

/**
 * @extends ServiceEntityRepository<Media>
 */
class MediaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Media::class);
    }

    /** @return Media[] */
    public function findLatest(): array
    {
        return $this->findBy([], ['id' => 'DESC']);
    }

    /** Media missing a title or alt (null or empty) — for the metadata backfill. */
    /** @return Media[] */
    public function findMissingMeta(): array
    {
        // Also picks rows missing pixel dimensions, so app:media:backfill-meta fills those too.
        return $this->createQueryBuilder('m')
            ->where('m.title IS NULL OR m.title = :empty OR m.alt IS NULL OR m.alt = :empty OR m.width IS NULL OR m.height IS NULL')
            ->setParameter('empty', '')
            ->orderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Paginated, newest-first listing for the media-library grid. Optional `$q` filters
     * (case-insensitive LIKE) on originalName/alt/title. Fetches one extra row to tell
     * the caller whether a further page exists, then trims to `$perPage`.
     *
     * @return array{items: Media[], hasMore: bool}
     */
    public function searchPaginated(?string $q, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $qb = $this->createQueryBuilder('m')
            ->orderBy('m.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage + 1);

        if (null !== $q && '' !== trim($q)) {
            $qb->andWhere('m.originalName LIKE :q OR m.alt LIKE :q OR m.title LIKE :q')
                ->setParameter('q', '%'.trim($q).'%');
        }

        $rows = $qb->getQuery()->getResult();
        $hasMore = \count($rows) > $perPage;

        return ['items' => \array_slice($rows, 0, $perPage), 'hasMore' => $hasMore];
    }
}
