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

    public static function normalise(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
