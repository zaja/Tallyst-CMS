<?php

namespace App\Repository;

use App\Entity\MemberSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MemberSession>
 */
class MemberSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MemberSession::class);
    }

    /**
     * Every remembered sign-in for one member, newest first — what a "your devices" screen will read
     * when it is built. Nothing renders it today.
     *
     * @return list<MemberSession>
     */
    public function findForIdentifier(string $identifier): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.userIdentifier = :id')
            ->setParameter('id', $identifier)
            ->orderBy('s.lastUsedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Drops sign-ins nobody has used inside the window. Set-based, so it costs the same shape of
     * work whatever the table size.
     */
    /** What deleteExpired() would remove — for the prune command's --dry-run. */
    public function countExpired(\DateTimeImmutable $notUsedSince): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.series)')
            ->andWhere('s.lastUsedAt < :cutoff')
            ->setParameter('cutoff', $notUsedSince)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function deleteExpired(\DateTimeImmutable $notUsedSince): int
    {
        return (int) $this->createQueryBuilder('s')
            ->delete()
            ->andWhere('s.lastUsedAt < :cutoff')
            ->setParameter('cutoff', $notUsedSince)
            ->getQuery()
            ->execute();
    }
}
