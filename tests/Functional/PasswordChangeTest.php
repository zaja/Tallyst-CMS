<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Self-service password change on the "Sigurnost" page: a correct current password changes it AND
 * keeps the session alive (the logout-on-password-change gotcha), while a wrong current password
 * or a weak new one is rejected. Needs the test DB.
 */
class PasswordChangeTest extends WebTestCase
{
    /** @var int[] */
    private array $userIds = [];

    public function testChangeKeepsSessionAndUpdatesPassword(): void
    {
        $client = static::createClient();
        $user = $this->createUser('Old-Passw0rd-1');
        $client->loginUser($user);

        $client->request('GET', '/admin/security');
        self::assertResponseIsSuccessful();

        $new = 'Totally-New-Pw-42';
        $client->submit($client->getCrawler()->filter('form')->form([
            'change_own_password[currentPassword]' => 'Old-Passw0rd-1',
            'change_own_password[newPassword][first]' => $new,
            'change_own_password[newPassword][second]' => $new,
        ]));
        self::assertResponseRedirects('/admin/security');

        // THE gotcha: still authenticated after changing your own password.
        $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        // Password actually changed in the DB.
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->getRepository(User::class)->find($user->getId());
        self::assertTrue($container->get(UserPasswordHasherInterface::class)->isPasswordValid($fresh, $new));
    }

    public function testWrongCurrentPasswordIsRejected(): void
    {
        $client = static::createClient();
        $user = $this->createUser('Old-Passw0rd-1');
        $client->loginUser($user);

        $client->request('GET', '/admin/security');
        $new = 'Totally-New-Pw-42';
        $client->submit($client->getCrawler()->filter('form')->form([
            'change_own_password[currentPassword]' => 'WRONG-current',
            'change_own_password[newPassword][first]' => $new,
            'change_own_password[newPassword][second]' => $new,
        ]));

        // Not a successful change (no PRG redirect to itself) and the password is unchanged.
        self::assertFalse($client->getResponse()->isRedirect(), 'invalid submit re-renders, not redirects');
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->getRepository(User::class)->find($user->getId());
        $hasher = $container->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($fresh, 'Old-Passw0rd-1'), 'old password still valid');
        self::assertFalse($hasher->isPasswordValid($fresh, $new), 'new password not applied');
    }

    public function testWeakNewPasswordIsRejected(): void
    {
        $client = static::createClient();
        $user = $this->createUser('Old-Passw0rd-1');
        $client->loginUser($user);

        $client->request('GET', '/admin/security');
        $client->submit($client->getCrawler()->filter('form')->form([
            'change_own_password[currentPassword]' => 'Old-Passw0rd-1',
            'change_own_password[newPassword][first]' => 'short',
            'change_own_password[newPassword][second]' => 'short',
        ]));

        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->getRepository(User::class)->find($user->getId());
        self::assertTrue(
            $container->get(UserPasswordHasherInterface::class)->isPasswordValid($fresh, 'Old-Passw0rd-1'),
            'weak new password rejected, old one kept'
        );
    }

    private function createUser(string $password): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = (new User('pwchange_'.bin2hex(random_bytes(6)).'@test.local'))->setRoles(['ROLE_ADMIN']);
        $user->setPassword($hasher->hashPassword($user, $password));
        $em->persist($user);
        $em->flush();
        $this->userIds[] = $user->getId();

        return $user;
    }

    protected function tearDown(): void
    {
        if ([] !== $this->userIds) {
            /** @var Connection $conn */
            $conn = static::getContainer()->get(Connection::class);
            foreach ($this->userIds as $id) {
                $conn->executeStatement('DELETE FROM `user` WHERE id = ?', [$id]);
            }
            $this->userIds = [];
        }

        parent::tearDown();
    }
}
