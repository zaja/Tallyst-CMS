<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use OTPHP\TOTP;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * End-to-end 2FA via the REAL form-login flow (NOT loginUser(), which bypasses the firewall
 * and would skip the challenge): existing users without 2FA log in straight through; users
 * with 2FA hit the challenge and pass with a valid TOTP, are rejected on a bad code, and can
 * use a one-time backup code; a password reset does NOT bypass 2FA; enrolment confirms before
 * activating. Needs the migrated test DB (see CLAUDE.md "Functional tests need a migrated test DB").
 *
 * NOTE: after the password step, the firewall redirects to the saved target (/admin), which then
 * bounces to /admin/2fa — so the tests FOLLOW redirects and assert on the landed page, not on the
 * single immediate Location header.
 */
class TwoFactorTest extends WebTestCase
{
    /** @var int[] */
    private array $userIds = [];

    public function testUserWithoutTwoFactorLogsInDirectly(): void
    {
        $client = static::createClient();
        $user = $this->createUser('Passw0rd-1234');

        $this->passwordLogin($client, $user, 'Passw0rd-1234');

        // No challenge — straight into the back-office.
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Two-factor authentication', $client->getResponse()->getContent());
    }

    public function testTwoFactorChallengeWithValidTotp(): void
    {
        $client = static::createClient();
        $secret = TOTP::generate()->getSecret();
        $user = $this->createUser('Passw0rd-1234', $secret);

        $this->passwordLogin($client, $user, 'Passw0rd-1234');
        self::assertStringContainsString('Two-factor authentication', $client->getResponse()->getContent(), 'lands on the 2FA challenge');

        $client->submit($client->getCrawler()->filter('form')->form(['_auth_code' => TOTP::createFromSecret($secret)->now()]));

        // Fully authenticated — on the dashboard, not the challenge.
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Two-factor authentication', $client->getResponse()->getContent());
    }

    public function testTwoFactorRejectsInvalidCode(): void
    {
        $client = static::createClient();
        $secret = TOTP::generate()->getSecret();
        $user = $this->createUser('Passw0rd-1234', $secret);

        $this->passwordLogin($client, $user, 'Passw0rd-1234');
        $client->submit($client->getCrawler()->filter('form')->form(['_auth_code' => '000000']));

        // Still on the challenge, and still gated: /admin bounces back to the 2FA form.
        self::assertStringContainsString('Two-factor authentication', $client->getResponse()->getContent());
        $client->followRedirects(false);
        $client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/2fa');
    }

    public function testBackupCodeAuthenticatesAndIsConsumed(): void
    {
        $client = static::createClient();
        $secret = TOTP::generate()->getSecret();
        $user = $this->createUser('Passw0rd-1234', $secret, 'RECOVER1234');

        $this->passwordLogin($client, $user, 'Passw0rd-1234');
        $client->submit($client->getCrawler()->filter('form')->form(['_auth_code' => 'RECOVER1234']));

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Two-factor authentication', $client->getResponse()->getContent());

        // One-time: the backup code is invalidated (persisted) after use.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->getRepository(User::class)->find($user->getId());
        self::assertFalse($fresh->isBackupCode('RECOVER1234'), 'used backup code cannot be reused');
    }

    public function testPasswordResetDoesNotBypassTwoFactor(): void
    {
        $client = static::createClient();
        $secret = TOTP::generate()->getSecret();
        $user = $this->createUser('OldPassw0rd-1', $secret);

        // Simulate a completed reset: the password changes, the 2FA fields are untouched.
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'NewPassw0rd-9'));
        $em->flush();

        $this->passwordLogin($client, $user, 'NewPassw0rd-9');

        // 2FA still gates the new password — reset is not a back door.
        self::assertStringContainsString('Two-factor authentication', $client->getResponse()->getContent());
    }

    public function testEnrolmentActivatesOnlyAfterValidCode(): void
    {
        $client = static::createClient();
        $user = $this->createUser('Passw0rd-1234');
        $client->loginUser($user); // no 2FA yet → fine to use loginUser for the enrolment pages

        // GET sets a fresh (unconfirmed) secret.
        $client->request('GET', '/admin/security/2fa/enable');
        self::assertResponseIsSuccessful();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $secret = $em->getRepository(User::class)->find($user->getId())->getTotpSecret();
        self::assertNotNull($secret);

        $client->submitForm('Potvrdi i uključi', ['code' => TOTP::createFromSecret($secret)->now()]);
        self::assertResponseRedirects('/admin/security/2fa/backup-codes');

        $em->clear();
        self::assertTrue($em->getRepository(User::class)->find($user->getId())->isTotpAuthenticationEnabled());
    }

    /** Submit the password form and follow redirects to wherever the firewall lands (dashboard or 2FA). */
    private function passwordLogin(KernelBrowser $client, User $user, string $password): void
    {
        $client->followRedirects(true);
        $crawler = $client->request('GET', '/admin/login');
        $client->submit($crawler->filter('form')->form(['_username' => $user->getEmail(), '_password' => $password]));
    }

    private function createUser(string $password, ?string $totpSecret = null, ?string $backupCode = null): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = (new User('2fa_'.bin2hex(random_bytes(6)).'@test.local'))->setRoles(['ROLE_ADMIN']);
        $user->setPassword($hasher->hashPassword($user, $password));
        if (null !== $totpSecret) {
            $user->setTotpSecret($totpSecret)->setTotpEnabled(true);
            if (null !== $backupCode) {
                $user->setBackupCodesFromPlain([$backupCode]);
            }
        }
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
