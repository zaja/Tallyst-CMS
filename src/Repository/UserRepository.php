<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => $email]);
    }

    /**
     * How many users hold ROLE_ADMIN. Filtered in PHP (the `roles` JSON column would need a
     * DB-specific JSON_CONTAINS) — fine at back-office user scale and DB-agnostic. Used by
     * AdminLockoutGuard to protect the last administrator.
     */
    public function countAdmins(): int
    {
        $count = 0;
        foreach ($this->findAll() as $user) {
            if (\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Transparently rehashes passwords as hashing algorithms improve.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }
}
