<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\AdminLockoutGuard;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

class AdminLockoutGuardTest extends TestCase
{
    private function guard(?User $currentUser, int $adminCount): AdminLockoutGuard
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('countAdmins')->willReturn($adminCount);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($currentUser);

        return new AdminLockoutGuard($users, $security);
    }

    private function user(string $email, array $roles): User
    {
        return (new User($email))->setRoles($roles);
    }

    /** EntityManager whose UnitOfWork reports the given pre-change roles for the entity. */
    private function emWithOriginalRoles(array $originalRoles): EntityManagerInterface
    {
        $uow = $this->createStub(UnitOfWork::class);
        $uow->method('getOriginalEntityData')->willReturn(['roles' => $originalRoles]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);

        return $em;
    }

    // --- delete ---

    public function testCannotDeleteYourself(): void
    {
        $me = $this->user('me@x.test', ['ROLE_ADMIN']);
        self::assertNotNull($this->guard($me, 5)->blockDelete($me));
    }

    public function testCannotDeleteLastAdmin(): void
    {
        $target = $this->user('admin@x.test', ['ROLE_ADMIN']);
        $current = $this->user('other@x.test', ['ROLE_ADMIN']);
        self::assertNotNull($this->guard($current, 1)->blockDelete($target));
    }

    public function testCanDeleteAnAdminWhenOthersRemain(): void
    {
        $target = $this->user('admin@x.test', ['ROLE_ADMIN']);
        $current = $this->user('other@x.test', ['ROLE_ADMIN']);
        self::assertNull($this->guard($current, 2)->blockDelete($target));
    }

    public function testCanDeleteAnEditor(): void
    {
        $target = $this->user('editor@x.test', ['ROLE_EDITOR']);
        $current = $this->user('admin@x.test', ['ROLE_ADMIN']);
        self::assertNull($this->guard($current, 1)->blockDelete($target));
    }

    // --- role change (demote) ---

    public function testCannotRemoveYourOwnAdminRole(): void
    {
        $me = $this->user('me@x.test', ['ROLE_EDITOR']); // new roles: admin removed
        $em = $this->emWithOriginalRoles(['ROLE_ADMIN']); // was admin
        self::assertNotNull($this->guard($me, 5)->blockRoleChange($em, $me));
    }

    public function testCannotRemoveAdminFromTheLastAdmin(): void
    {
        $target = $this->user('admin@x.test', ['ROLE_EDITOR']);
        $current = $this->user('other@x.test', ['ROLE_ADMIN']);
        $em = $this->emWithOriginalRoles(['ROLE_ADMIN']);
        self::assertNotNull($this->guard($current, 1)->blockRoleChange($em, $target));
    }

    public function testCanDemoteAnAdminWhenOthersRemain(): void
    {
        $target = $this->user('admin@x.test', ['ROLE_EDITOR']);
        $current = $this->user('other@x.test', ['ROLE_ADMIN']);
        $em = $this->emWithOriginalRoles(['ROLE_ADMIN']);
        self::assertNull($this->guard($current, 2)->blockRoleChange($em, $target));
    }

    public function testNoBlockWhenAdminRoleIsKept(): void
    {
        $target = $this->user('admin@x.test', ['ROLE_ADMIN', 'ROLE_EDITOR']);
        $em = $this->emWithOriginalRoles(['ROLE_ADMIN']);
        self::assertNull($this->guard(null, 1)->blockRoleChange($em, $target));
    }

    public function testNoBlockWhenUserWasNeverAdmin(): void
    {
        $target = $this->user('editor@x.test', ['ROLE_EDITOR']);
        $em = $this->emWithOriginalRoles(['ROLE_EDITOR']);
        self::assertNull($this->guard(null, 1)->blockRoleChange($em, $target));
    }
}
