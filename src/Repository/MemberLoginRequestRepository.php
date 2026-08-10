<?php

namespace App\Repository;

use App\Entity\MemberLoginRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MemberLoginRequest>
 */
class MemberLoginRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MemberLoginRequest::class);
    }

    public function findBySelector(string $selector): ?MemberLoginRequest
    {
        return $this->findOneBy(['selector' => $selector]);
    }

    /**
     * How many links have been asked for THIS address since the given moment. Throttling is per
     * address rather than per IP on purpose: the harm being prevented is someone else's mailbox
     * being buried in login mail, and that harm is addressed to a person, not to a connection.
     */
    public function countSince(string $email, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('LOWER(r.email) = :email')
            ->andWhere('r.requestedAt >= :since')
            ->setParameter('email', MemberRepository::normalise($email))
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Sweeps requests nobody ever confirmed. Set-based, so it costs the same shape of work whatever
     * the table size. Confirmed requests are already gone — they are deleted when spent.
     */
    public function deleteExpired(?\DateTimeImmutable $now = null): int
    {
        return (int) $this->createQueryBuilder('r')
            ->delete()
            ->andWhere('r.expiresAt < :now')
            ->setParameter('now', $now ?? new \DateTimeImmutable())
            ->getQuery()
            ->execute();
    }
}
