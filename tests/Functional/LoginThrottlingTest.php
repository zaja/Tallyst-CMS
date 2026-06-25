<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Login throttling: after max_attempts failed logins, even the CORRECT password is blocked.
 * The test clears the rate-limiter pool (setUp + tearDown) and uses a UNIQUE username so it's
 * deterministic via the local (username+IP) limiter regardless of test order. Needs the test DB.
 */
class LoginThrottlingTest extends WebTestCase
{
    private const int MAX_ATTEMPTS = 5;

    /** @var int[] */
    private array $userIds = [];

    public function testTooManyFailedAttemptsBlockEvenTheCorrectPassword(): void
    {
        $client = static::createClient();
        $this->clearRateLimiter();
        $user = $this->createUser('Correct-Passw0rd-1');

        // Exhaust the local limiter with wrong passwords.
        for ($i = 0; $i < self::MAX_ATTEMPTS; ++$i) {
            $crawler = $client->request('GET', '/admin/login');
            $client->submit($crawler->filter('form')->form(['_username' => $user->getEmail(), '_password' => 'wrong-'.$i]));
        }

        // Next attempt uses the CORRECT password — still bounced to login (throttled), not /admin.
        $crawler = $client->request('GET', '/admin/login');
        $client->submit($crawler->filter('form')->form(['_username' => $user->getEmail(), '_password' => 'Correct-Passw0rd-1']));
        self::assertResponseRedirects('/admin/login');

        // And not authenticated: an admin page bounces to login.
        $client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/login');
    }

    private function clearRateLimiter(): void
    {
        /** @var CacheItemPoolInterface $pool */
        $pool = static::getContainer()->get('cache.rate_limiter');
        $pool->clear();
    }

    private function createUser(string $password): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = (new User('throttle_'.bin2hex(random_bytes(6)).'@test.local'))->setRoles(['ROLE_ADMIN']);
        $user->setPassword($hasher->hashPassword($user, $password));
        $em->persist($user);
        $em->flush();
        $this->userIds[] = $user->getId();

        return $user;
    }

    protected function tearDown(): void
    {
        if (static::$booted) {
            $this->clearRateLimiter();
            if ([] !== $this->userIds) {
                /** @var Connection $conn */
                $conn = static::getContainer()->get(Connection::class);
                foreach ($this->userIds as $id) {
                    $conn->executeStatement('DELETE FROM `user` WHERE id = ?', [$id]);
                }
                $this->userIds = [];
            }
        }

        parent::tearDown();
    }
}
