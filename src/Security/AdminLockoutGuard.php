<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Server-side guard rails that stop an admin from locking everyone (or themselves) out of
 * the back-office. Pure decision logic — returns a human message when an operation must be
 * blocked, or null when it's allowed; the caller (UserCrudController) turns a message into a
 * flash + abort (never a 500, never the mutation).
 *
 * "Last admin" is counted from the DB (pre-flush), so it reflects reality at decision time.
 */
class AdminLockoutGuard
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly Security $security,
    ) {
    }

    /** @return string|null block message, or null if the delete is allowed */
    public function blockDelete(User $target): ?string
    {
        if ($this->isCurrentUser($target)) {
            return 'Ne možeš obrisati vlastiti račun.';
        }
        if ($this->isAdmin($target) && $this->users->countAdmins() <= 1) {
            return 'Ne možeš obrisati zadnjeg administratora.';
        }

        return null;
    }

    /**
     * Blocks an edit that strips ROLE_ADMIN from the last admin or from yourself. The
     * pre-change roles come from the UnitOfWork (the entity already carries the new roles).
     *
     * @return string|null block message, or null if the change is allowed
     */
    public function blockRoleChange(EntityManagerInterface $em, User $target): ?string
    {
        /** @var array{roles?: string[]} $original */
        $original = $em->getUnitOfWork()->getOriginalEntityData($target);
        $wasAdmin = \in_array('ROLE_ADMIN', $original['roles'] ?? [], true);

        if (!$wasAdmin || $this->isAdmin($target)) {
            return null; // not removing admin from an admin
        }

        if ($this->isCurrentUser($target)) {
            return 'Ne možeš ukloniti vlastitu administratorsku rolu.';
        }
        if ($this->users->countAdmins() <= 1) {
            return 'Ne možeš ukloniti zadnju administratorsku rolu.';
        }

        return null;
    }

    private function isAdmin(User $user): bool
    {
        return \in_array('ROLE_ADMIN', $user->getRoles(), true);
    }

    private function isCurrentUser(User $user): bool
    {
        $current = $this->security->getUser();

        return $current instanceof User && $current->getUserIdentifier() === $user->getUserIdentifier();
    }
}
