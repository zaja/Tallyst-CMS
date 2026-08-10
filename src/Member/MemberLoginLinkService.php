<?php

namespace App\Member;

use App\Entity\Member;
use App\Entity\MemberLoginRequest;
use App\Repository\MemberLoginRequestRepository;
use App\Repository\MemberRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Issues and verifies the e-mail login link — the only credential a member has.
 *
 * ⚠ RESOLVE AND CONFIRM ARE TWO DIFFERENT THINGS, AND THE SPLIT IS THE POINT. `resolve()` answers
 * "is this link valid?" and changes nothing; `confirm()` spends it. Opening the link only resolves,
 * and a button press confirms. Corporate mail filters and some clients fetch every URL in a message
 * before a human sees it — if opening spent the token, those members would hit "this link has
 * expired" every time, reproducibly, and it would look like our fault. The same split stops a link
 * from signing in somebody who clicked without meaning to.
 *
 * ⚠ Security properties are mirrored, deliberately, from the admin password-reset flow: a split
 * token (public selector, hashed verifier), single use, a short expiry, per-address throttling, and
 * an identical answer whether or not the address is known. The reset bundle itself could not be
 * reused because its request row requires an existing User, and ours is issued to an address that
 * usually has no account at all.
 */
class MemberLoginLinkService
{
    /** Long enough that a link survives a slow inbox, short enough that a forwarded mail goes stale. */
    public const LIFETIME = '+30 minutes';

    /** Per address, within the window below. Generous for a real person, useless for burying a mailbox. */
    public const MAX_REQUESTS = 5;
    public const THROTTLE_WINDOW = '-1 hour';

    public function __construct(
        private readonly MemberLoginRequestRepository $requests,
        private readonly MemberRepository $members,
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    public function isAllowedToRequest(string $email): bool
    {
        return $this->requests->countSince(
            $email,
            new \DateTimeImmutable(self::THROTTLE_WINDOW),
        ) < self::MAX_REQUESTS;
    }

    /**
     * Creates a request and returns both token halves in the clear — the ONLY moment the verifier
     * exists in readable form. Nothing here checks whether an account exists: a link is issued to an
     * address, and whether that address is anybody's is decided when the link is confirmed.
     */
    public function issue(string $email, ?\DateTimeImmutable $expiresAt = null): LoginLinkResult
    {
        $email = MemberRepository::normalise($email);
        $selector = bin2hex(random_bytes(16));
        $verifier = bin2hex(random_bytes(32));
        $expiresAt ??= new \DateTimeImmutable(self::LIFETIME);

        $this->em->persist(new MemberLoginRequest($email, $selector, $this->hash($verifier), $expiresAt));
        // Flushed here, not left to a later one: the mail goes out immediately after this returns,
        // and a link whose request was never written is a link that is broken the moment it lands.
        $this->em->flush();

        return new LoginLinkResult($email, $selector, $verifier, $expiresAt);
    }

    /**
     * Validates without spending. Returns null for anything wrong — unknown selector, bad verifier,
     * expired — with no distinction between them, so a caller cannot learn which.
     */
    public function resolve(string $selector, string $verifier): ?MemberLoginRequest
    {
        $request = $this->requests->findBySelector($selector);
        if (null === $request || $request->isExpired()) {
            return null;
        }

        // hash_equals, not ===, so a timing difference cannot leak how much of the verifier matched.
        if (!hash_equals($request->getHashedToken(), $this->hash($verifier))) {
            return null;
        }

        return $request;
    }

    /**
     * Spends the link: deletes it, then finds or creates the account for its address.
     *
     * ⚠ The request row is DELETED rather than flagged as used. A spent link and a link that never
     * existed have to be indistinguishable, or the difference itself reveals that an address is in
     * use. Returns null on any failure, again without saying which.
     */
    public function confirm(string $selector, string $verifier): ?Member
    {
        $request = $this->resolve($selector, $verifier);
        if (null === $request) {
            return null;
        }

        $this->em->remove($request);

        $member = $this->members->findByEmail($request->getEmail());
        $created = null === $member;
        if ($created) {
            $member = new Member($request->getEmail());
            $this->em->persist($member);
        }

        $member->touchLastLogin();
        $this->em->flush();

        // After the flush, so a listener attaching sales is working with a member that has an id.
        $this->events->dispatch(new MemberAuthenticatedEvent($member, $created));

        return $member;
    }

    private function hash(string $verifier): string
    {
        // Fast hashing is correct here, unlike for a password: the verifier is 32 random bytes, so
        // there is nothing to guess and nothing to slow an attacker down about.
        return hash('sha256', $verifier);
    }
}
