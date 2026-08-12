<?php

namespace App\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The per-client ceiling on login-link mail.
 *
 * ⚠ WHY THIS EXISTS. The login form is the only way an unknown visitor can make the site send mail,
 * and the harm lands on the SITE OWNER's standing with their mail provider: a flood of invented
 * addresses bounces hard, a bouncing sender gets frozen, and a frozen sender stops delivering ORDER
 * CONFIRMATIONS as well. Measured before this guard: 200 distinct addresses from one IP produced 200
 * mails, 1:1, with a single reused CSRF token — roughly 72,000/hour at a modest request rate.
 *
 * The per-ADDRESS limit already in MemberLoginLinkService cannot see this: every address is new.
 */
class MemberLoginRateLimitTest extends WebTestCase
{
    /** Matches the `member_login` bucket in config/packages/rate_limiter.yaml. */
    private const int BURST = 20;

    protected function setUp(): void
    {
        $this->reset();
    }

    protected function tearDown(): void
    {
        $this->reset();
        parent::tearDown();
    }

    /**
     * ⚠ Both halves matter. The limiter lives in a cache pool, so a leftover bucket from another
     * test makes this one non-deterministic; and the rows this test creates are counted whole by
     * other tests, so they have to go.
     */
    private function reset(): void
    {
        self::bootKernel();
        /** @var CacheItemPoolInterface $pool */
        $pool = static::getContainer()->get('cache.rate_limiter');
        $pool->clear();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery("DELETE FROM App\Entity\MemberLoginRequest r WHERE r.email LIKE 'ratelimit-%'")->execute();
        static::ensureKernelShutdown();
    }

    private function token(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/account/login');

        return (string) $crawler->filter('input[name="_token"]')->attr('value');
    }

    /** One POST from $ip. Returns the crawler for the page the visitor ends up on. */
    private function ask(KernelBrowser $client, string $token, string $email, string $ip): Crawler
    {
        $client->request('POST', '/account/login', ['email' => $email, '_token' => $token], [], ['REMOTE_ADDR' => $ip]);

        return $client->followRedirect();
    }

    private function issued(): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        return (int) $em->createQuery("SELECT COUNT(r.id) FROM App\Entity\MemberLoginRequest r WHERE r.email LIKE 'ratelimit-%'")
            ->getSingleScalarResult();
    }

    /**
     * ⚠ THE ONE THAT PROVES WE DID NOT BREAK ORDINARY USE. A real person needs one link (a sign-in
     * lasts 90 days); somebody who mistypes their address needs a handful. None of them may notice
     * this guard exists.
     */
    public function testAnOrdinaryVisitorIsNotAffected(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $token = $this->token($client);

        for ($i = 0; $i < 4; ++$i) {
            $this->ask($client, $token, 'ratelimit-person@example.test', '198.51.100.10');
        }

        // Four in a row, one address: the per-address limit (5/hour) allows this and so must we.
        self::assertSame(4, $this->issued());
    }

    /**
     * The burst is sized on an office being onboarded at once — twenty people, one address at the
     * door. All twenty must get their link immediately.
     */
    public function testAnOfficeBehindOneAddressGetsThroughInFull(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $token = $this->token($client);

        for ($i = 0; $i < self::BURST; ++$i) {
            $this->ask($client, $token, \sprintf('ratelimit-colleague-%d@example.test', $i), '198.51.100.20');
        }

        self::assertSame(self::BURST, $this->issued());
    }

    /**
     * ⚠ THE ONE THAT CLOSES THE HOLE. Past the burst the site stops sending, however many new
     * addresses are offered and however fresh each one is.
     */
    public function testAFloodOfNewAddressesIsCutOff(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $token = $this->token($client);

        for ($i = 0; $i < 60; ++$i) {
            $this->ask($client, $token, \sprintf('ratelimit-flood-%d@example.test', $i), '198.51.100.30');
        }

        // 60 attempts, and the bucket refills one a minute — inside one test run nothing refills.
        self::assertSame(self::BURST, $this->issued(), 'sending must stop at the burst, not follow the attempts');
    }

    /**
     * ⚠ THE IDENTICAL-ANSWER RULE, WHICH THE LIMIT MUST NOT BREAK. A refused request has to look
     * exactly like an accepted one — same status, same destination, same page. Anything else turns
     * the form into a way of checking whether an address is worth attacking, which is the whole
     * reason the flow answers the same way for known and unknown addresses.
     */
    public function testARefusedRequestIsIndistinguishableFromAnAcceptedOne(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $token = $this->token($client);

        $accepted = $this->ask($client, $token, 'ratelimit-first@example.test', '198.51.100.40');
        $acceptedStatus = $client->getResponse()->getStatusCode();
        $acceptedUrl = $client->getRequest()->getUri();
        $acceptedHtml = $accepted->filter('body')->html();

        // Drain the bucket, then ask once more — this one is refused.
        for ($i = 0; $i < self::BURST + 5; ++$i) {
            $this->ask($client, $token, \sprintf('ratelimit-drain-%d@example.test', $i), '198.51.100.40');
        }
        $refused = $this->ask($client, $token, 'ratelimit-last@example.test', '198.51.100.40');

        self::assertSame($acceptedStatus, $client->getResponse()->getStatusCode(), 'a refusal must not answer 429');
        self::assertSame($acceptedUrl, $client->getRequest()->getUri(), 'same destination');
        self::assertSame($acceptedHtml, $refused->filter('body')->html(), 'byte-identical page');
    }

    /** One visitor's flood must not lock out everybody else on the site. */
    public function testAnotherAddressIsUnaffectedByTheFirstOnesFlood(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $token = $this->token($client);

        for ($i = 0; $i < self::BURST + 10; ++$i) {
            $this->ask($client, $token, \sprintf('ratelimit-noisy-%d@example.test', $i), '198.51.100.50');
        }
        $before = $this->issued();

        $this->ask($client, $token, 'ratelimit-innocent@example.test', '198.51.100.51');

        self::assertSame($before + 1, $this->issued(), 'a different client has its own bucket');
    }
}
