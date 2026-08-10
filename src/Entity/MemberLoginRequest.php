<?php

namespace App\Entity;

use App\Repository\MemberLoginRequestRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One outstanding login link, issued to an E-MAIL ADDRESS rather than to an account.
 *
 * ⚠ THIS IS WHY THE PASSWORD-RESET MACHINERY COULD NOT BE REUSED. `ResetPasswordRequest` holds a
 * `ManyToOne` to User with `nullable: false`, so it presupposes an existing account. Our link is
 * usually issued to an address that has no account yet — the account is created when the link is
 * confirmed, not before. The field names below deliberately mirror the reset bundle's
 * (selector / hashedToken / requestedAt / expiresAt) so the two read the same way.
 *
 * ⚠ SPLIT TOKEN, not one secret. The link carries `selector.verifier`. The selector is stored in
 * the clear and is what we look up by; only a HASH of the verifier is stored. A leaked database
 * therefore yields no usable links, and lookup stays a single indexed query rather than hashing
 * every row (which is both slow and a timing side-channel).
 *
 * ⚠ CONSUMED MEANS DELETED. There is no "used" flag on purpose: a spent link and a link that never
 * existed must be indistinguishable, or the difference itself tells an attacker that an address is
 * in use. Unconfirmed requests expire and are swept for the same reason.
 */
#[ORM\Entity(repositoryClass: MemberLoginRequestRepository::class)]
#[ORM\Table(name: 'member_login_request')]
#[ORM\Index(name: 'idx_mlr_email', columns: ['email'])]
class MemberLoginRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, unique: true)]
    private string $selector;

    #[ORM\Column(length: 100)]
    private string $hashedToken;

    /**
     * The address this was issued for. NOT a Member relation — at issue time there is usually no
     * Member, and creating one here would be exactly the thing the founding rule forbids.
     */
    #[ORM\Column(length: 191)]
    private string $email;

    #[ORM\Column]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    public function __construct(string $email, string $selector, string $hashedToken, \DateTimeImmutable $expiresAt)
    {
        $this->email = $email;
        $this->selector = $selector;
        $this->hashedToken = $hashedToken;
        $this->expiresAt = $expiresAt;
        $this->requestedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSelector(): string
    {
        return $this->selector;
    }

    public function getHashedToken(): string
    {
        return $this->hashedToken;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return ($now ?? new \DateTimeImmutable()) >= $this->expiresAt;
    }
}
