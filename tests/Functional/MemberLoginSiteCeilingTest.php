<?php

namespace App\Tests\Functional;

use App\Member\LoginFloodMonitor;
use App\Readiness\MemberLoginReadinessProvider;
use App\Readiness\Status;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The ceiling for the whole site, and the fact that the owner can see it happened.
 *
 * ⚠ The per-client bucket caps the RATE and sets no total: a patient attacker spread over a few
 * addresses still gets thousands of mails a day out of the site, which is enough hard bounces to
 * freeze a sending account — and a frozen account stops ORDER CONFIRMATIONS as well. This is the
 * limit that puts a number on the day.
 */
class MemberLoginSiteCeilingTest extends WebTestCase
{
    /** Matches `member_login_site` in config/packages/rate_limiter.yaml. */
    private const int CEILING = 200;

    protected function setUp(): void
    {
        $this->reset();
    }

    protected function tearDown(): void
    {
        $this->reset();
        parent::tearDown();
    }

    private function reset(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        /** @var CacheItemPoolInterface $limiterPool */
        $limiterPool = $c->get('cache.rate_limiter');
        $limiterPool->clear();
        /** @var CacheItemPoolInterface $appPool */
        $appPool = $c->get('cache.app');
        $appPool->clear();

        $em = $c->get(EntityManagerInterface::class);
        $em->createQuery("DELETE FROM App\Entity\MemberLoginRequest r WHERE r.email LIKE 'ceiling-%'")->execute();
        $em->createQuery("DELETE FROM App\Entity\Setting s WHERE s.name = :k")
            ->setParameter('k', LoginFloodMonitor::SETTING_KEY)->execute();
        static::ensureKernelShutdown();
    }

    private function issued(): int
    {
        return (int) static::getContainer()->get(EntityManagerInterface::class)
            ->createQuery("SELECT COUNT(r.id) FROM App\Entity\MemberLoginRequest r WHERE r.email LIKE 'ceiling-%'")
            ->getSingleScalarResult();
    }

    /**
     * ⚠ THE ONE THAT SAVES THE SENDING ACCOUNT. Past the ceiling the site stops sending, no matter
     * how many clients ask or how fresh each address is — so the flood cannot keep bouncing all day.
     */
    public function testTheSiteStopsSendingPastTheCeiling(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $crawler = $client->request('GET', '/account/login');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        // Spread over many clients so the per-client bucket is never the thing that stops it.
        for ($i = 0; $i < self::CEILING + 60; ++$i) {
            $client->request('POST', '/account/login', [
                'email' => \sprintf('ceiling-%d@example.test', $i),
                '_token' => $token,
            ], [], ['REMOTE_ADDR' => \sprintf('203.0.113.%d', $i % 200)]);
            $client->followRedirect();
        }

        self::assertSame(self::CEILING, $this->issued(), 'the whole site is capped, not just one client');
    }

    /**
     * ⚠ DOPUNA 1 — the owner has to hear this from Tallyst, not from their mail provider once the
     * account is already frozen. The refusal is invisible to the visitor by design, so the readiness
     * panel is the only place it can surface in time.
     */
    public function testTheOwnerSeesItInTheReadinessPanel(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $crawler = $client->request('GET', '/account/login');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        for ($i = 0; $i < self::CEILING + 5; ++$i) {
            $client->request('POST', '/account/login', [
                'email' => \sprintf('ceiling-seen-%d@example.test', $i),
                '_token' => $token,
            ], [], ['REMOTE_ADDR' => \sprintf('203.0.113.%d', $i % 200)]);
            $client->followRedirect();
        }

        /** @var MemberLoginReadinessProvider $provider */
        $provider = static::getContainer()->get(MemberLoginReadinessProvider::class);
        $checks = iterator_to_array($provider->getChecks());

        self::assertCount(1, $checks, 'a refusal must produce exactly one row');
        self::assertSame(Status::WARNING, $checks[0]->status);
        self::assertStringContainsString('sign-in links', $checks[0]->detail);
    }

    /**
     * ⚠ A quiet site shows NO row. The panel is read by somebody deciding what to act on, so it must
     * not carry a permanent line about an attack that never happened.
     */
    public function testAQuietSiteShowsNothingAtAll(): void
    {
        self::bootKernel();

        /** @var MemberLoginReadinessProvider $provider */
        $provider = static::getContainer()->get(MemberLoginReadinessProvider::class);

        self::assertSame([], iterator_to_array($provider->getChecks()));
    }
}
