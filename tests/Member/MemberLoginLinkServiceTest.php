<?php

namespace App\Tests\Member;

use App\Member\MemberLoginLinkService;
use App\Member\LoginLinkResult;
use App\Entity\MemberLoginRequest;
use App\Repository\MemberLoginRequestRepository;
use App\Repository\MemberRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * The login link is the ONLY credential a customer has, so these tests are about what the link
 * must and must not allow. The one that matters most is the first: opening a link cannot spend it.
 */
class MemberLoginLinkServiceTest extends TestCase
{
    /** @var list<MemberLoginRequest> */
    private array $stored = [];
    /** @var list<object> */
    private array $removed = [];

    private function service(int $recentRequests = 0): MemberLoginLinkService
    {
        $requests = $this->createStub(MemberLoginRequestRepository::class);
        $requests->method('countSince')->willReturn($recentRequests);
        $requests->method('findBySelector')->willReturnCallback(
            fn (string $s): ?MemberLoginRequest => array_values(array_filter(
                $this->stored, static fn (MemberLoginRequest $r): bool => $r->getSelector() === $s,
            ))[0] ?? null,
        );

        $members = $this->createStub(MemberRepository::class);
        $members->method('findByEmail')->willReturn(null);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $e): void {
            if ($e instanceof MemberLoginRequest) {
                $this->stored[] = $e;
            }
        });
        $em->method('remove')->willReturnCallback(function (object $e): void {
            $this->removed[] = $e;
            $this->stored = array_values(array_filter($this->stored, static fn ($r): bool => $r !== $e));
        });

        return new MemberLoginLinkService(
            $requests,
            $members,
            $em,
            $this->createStub(EventDispatcherInterface::class),
        );
    }

    /**
     * ⚠ THE ONE THAT MATTERS. Corporate mail filters and some clients fetch every link in a message
     * before a human ever sees it. If opening spent the token, those customers would meet "this link
     * has expired" every single time, reproducibly, and it would look like our bug.
     */
    public function testOpeningALinkDoesNotSpendIt(): void
    {
        $s = $this->service();
        $link = $s->issue('pero@example.com');

        $first = $s->resolve($link->selector, $link->verifier);
        $second = $s->resolve($link->selector, $link->verifier);

        self::assertNotNull($first, 'opening must resolve');
        self::assertNotNull($second, 'opening a second time must STILL resolve — nothing was spent');
        self::assertSame([], $this->removed, 'resolve() must not delete the request');
    }

    public function testConfirmingSpendsTheLinkAndASecondUseFails(): void
    {
        $s = $this->service();
        $link = $s->issue('pero@example.com');

        self::assertNotNull($s->confirm($link->selector, $link->verifier));
        self::assertNull($s->confirm($link->selector, $link->verifier), 'a spent link must not work twice');
    }

    public function testAWrongVerifierIsRejectedEvenWithTheRightSelector(): void
    {
        $s = $this->service();
        $link = $s->issue('pero@example.com');

        self::assertNull($s->resolve($link->selector, 'not-the-verifier'));
        self::assertNull($s->confirm($link->selector, 'not-the-verifier'));
    }

    public function testAnExpiredLinkIsRejected(): void
    {
        $s = $this->service();
        $link = $s->issue('pero@example.com', new \DateTimeImmutable('-1 hour'));

        self::assertNull($s->resolve($link->selector, $link->verifier));
    }

    public function testAnUnknownSelectorIsRejectedWithoutAnError(): void
    {
        self::assertNull($this->service()->resolve('nosuchselector', 'whatever'));
    }

    /**
     * Throttling is per ADDRESS, not per IP: the harm being prevented is somebody else's mailbox
     * being buried in login mail, and that harm is addressed to a person.
     */
    public function testTooManyRequestsForOneAddressAreRefused(): void
    {
        self::assertFalse($this->service(recentRequests: 99)->isAllowedToRequest('pero@example.com'));
        self::assertTrue($this->service(recentRequests: 0)->isAllowedToRequest('pero@example.com'));
    }

    /** Two links issued back to back must not collide or be guessable from one another. */
    public function testEachIssuedLinkIsDistinct(): void
    {
        $s = $this->service();
        $a = $s->issue('pero@example.com');
        $b = $s->issue('pero@example.com');

        self::assertNotSame($a->selector, $b->selector);
        self::assertNotSame($a->verifier, $b->verifier);
    }

    /** The verifier is never stored as given — only a hash of it. */
    public function testTheStoredRequestDoesNotContainTheVerifier(): void
    {
        $s = $this->service();
        $link = $s->issue('pero@example.com');

        self::assertNotSame($link->verifier, $this->stored[0]->getHashedToken());
        self::assertStringNotContainsString($link->verifier, $this->stored[0]->getHashedToken());
    }

    public function testIssueReturnsTheAddressItWasIssuedFor(): void
    {
        $link = $this->service()->issue('  Pero@Example.com ');

        self::assertInstanceOf(LoginLinkResult::class, $link);
        self::assertSame('pero@example.com', $link->email, 'the address is normalised at issue time');
    }
}
