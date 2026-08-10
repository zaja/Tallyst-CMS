<?php

namespace App\Repository;

use App\Entity\Customer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Customer>
 */
class CustomerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Customer::class);
    }

    /**
     * Addresses are matched case-insensitively and without surrounding space: a buyer who typed
     * "Pero@Example.com " at one provider and "pero@example.com" at another is one person, and two
     * accounts for one mailbox would split their purchase history for no reason they could see.
     */
    public function findByEmail(string $email): ?Customer
    {
        return $this->createQueryBuilder('c')
            ->andWhere('LOWER(c.email) = :email')
            ->setParameter('email', self::normalise($email))
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Accounts matching a fragment of an address — how an admin finds the right one when assigning
     * an order by hand. Capped, because this is a picker and not a report.
     *
     * @return list<Customer>
     */
    public function search(string $term, int $limit = 20): array
    {
        $term = trim($term);
        if ('' === $term) {
            return [];
        }

        return $this->createQueryBuilder('c')
            ->andWhere('LOWER(c.email) LIKE :term')
            ->setParameter('term', '%'.self::normalise($term).'%')
            ->orderBy('c.email', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public static function normalise(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
