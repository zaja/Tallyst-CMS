<?php

namespace App\Tests\Functional;

use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * End-to-end coverage of the forgot-password flow: request (+ async e-mail), anti-enumeration
 * (an unknown e-mail gives the SAME response and sends nothing), reset with a valid token
 * (password actually changes), and rejection of an invalid token.
 */
class ResetPasswordTest extends WebTestCase
{
    use MailerAssertionsTrait;

    /** @var int[] */
    private array $userIds = [];

    public function testRequestForKnownUserQueuesEmail(): void
    {
        $client = static::createClient();
        $user = $this->createUser('oldpw');

        $client->request('GET', '/admin/reset-password');
        $client->submit($client->getCrawler()->filter('form')->form(['reset_password_request_form[email]' => $user->getEmail()]));

        self::assertResponseRedirects('/admin/reset-password/check-email');
        self::assertQueuedEmailCount(1);
        self::assertSame(1, $this->resetRequestCountFor($user));
    }

    public function testUnknownEmailGivesSameResponseAndSendsNothing(): void
    {
        $client = static::createClient();

        $client->request('GET', '/admin/reset-password');
        $client->submit($client->getCrawler()->filter('form')->form(['reset_password_request_form[email]' => 'nobody-'.bin2hex(random_bytes(4)).'@test.local']));

        // Anti-enumeration: identical redirect to known-user case, but no e-mail.
        self::assertResponseRedirects('/admin/reset-password/check-email');
        self::assertQueuedEmailCount(0);
    }

    public function testResetWithValidTokenChangesPassword(): void
    {
        $client = static::createClient();
        $user = $this->createUser('oldpw');

        $token = static::getContainer()->get(ResetPasswordHelperInterface::class)
            ->generateResetToken($user)->getToken();

        // /reset/<token> stores the token in session then redirects to the form.
        $client->request('GET', '/admin/reset-password/reset/'.$token);
        $client->followRedirect();

        $new = 'Tallyst-N0va-2026!';
        $client->submit($client->getCrawler()->filter('form')->form([
            'change_password_form[plainPassword][first]' => $new,
            'change_password_form[plainPassword][second]' => $new,
        ]));
        self::assertResponseRedirects('/admin/login');

        // Re-read from DB and confirm the new password works (login would succeed).
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->getRepository(User::class)->find($user->getId());
        self::assertTrue(
            static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($fresh, $new),
            'password was changed to the new one'
        );
    }

    public function testInvalidTokenIsRejected(): void
    {
        $client = static::createClient();

        $client->request('GET', '/admin/reset-password/reset/'.str_repeat('a', 40));
        $client->followRedirect();

        // Invalid token → not the reset form; bounced back to the request page with an error.
        self::assertResponseRedirects('/admin/reset-password');
    }

    private function createUser(string $password): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = (new User('reset_'.bin2hex(random_bytes(6)).'@test.local'))->setRoles(['ROLE_ADMIN']);
        $user->setPassword($hasher->hashPassword($user, $password));
        $em->persist($user);
        $em->flush();
        $this->userIds[] = $user->getId();

        return $user;
    }

    private function resetRequestCountFor(User $user): int
    {
        return (int) static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(ResetPasswordRequest::class)->count(['user' => $user->getId()]);
    }

    protected function tearDown(): void
    {
        if ([] !== $this->userIds) {
            /** @var Connection $conn */
            $conn = static::getContainer()->get(Connection::class);
            foreach ($this->userIds as $id) {
                $conn->executeStatement('DELETE FROM reset_password_request WHERE user_id = ?', [$id]);
                $conn->executeStatement('DELETE FROM `user` WHERE id = ?', [$id]);
            }
            $this->userIds = [];
        }

        parent::tearDown();
    }
}
