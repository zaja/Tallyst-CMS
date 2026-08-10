<?php

namespace App\Entity;

use App\Repository\MemberSessionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * ONE remembered sign-in: this member, on this device, since this moment.
 *
 * ⚠ A ROW PER SIGN-IN, not a lifetime flag on the member. A person signs in from a laptop and a
 * phone and each is its own row, so "sign me out" can mean one device rather than all of them, and
 * a future "your devices" screen is a read of this table instead of a change to how sign-ins are
 * stored. Storing the shape correctly now is the whole point: bolting device history onto a single
 * shared token later means re-issuing everybody's sign-in.
 *
 * ⚠ SIGNING OUT DELETES THE ROW. It is not enough to clear the cookie — a cookie that still matches
 * a live row is still a valid sign-in for anyone holding it. The row is the sign-in; removing it is
 * what ends it.
 *
 * Lifetime is 90 days from LAST USE, not from creation: Symfony refreshes the token on every
 * request that uses it, so somebody who visits weekly stays signed in indefinitely and somebody who
 * disappears for three months has to ask for a new link.
 *
 * Implements Symfony's persistent-token contract so the framework keeps doing the cookie handling,
 * refreshing and sign-out; only the storage is ours, because the built-in table has no room for a
 * device or an address.
 */
#[ORM\Entity(repositoryClass: MemberSessionRepository::class)]
#[ORM\Table(name: 'member_session')]
#[ORM\Index(name: 'idx_ms_identifier', columns: ['user_identifier'])]
class MemberSession
{
    #[ORM\Id]
    #[ORM\Column(length: 88)]
    private string $series;

    #[ORM\Column(length: 88)]
    private string $tokenValue;

    /** The member's e-mail — Symfony's own key for a persistent token. */
    #[ORM\Column(length: 191)]
    private string $userIdentifier;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastUsedAt;

    /** Truncated: enough to recognise a device in a list, not a fingerprint worth keeping in full. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    public function __construct(
        string $series,
        string $tokenValue,
        string $userIdentifier,
        \DateTimeImmutable $lastUsedAt,
        ?string $userAgent = null,
        ?string $ipAddress = null,
    ) {
        $this->series = $series;
        $this->tokenValue = $tokenValue;
        $this->userIdentifier = $userIdentifier;
        $this->createdAt = new \DateTimeImmutable();
        $this->lastUsedAt = $lastUsedAt;
        $this->userAgent = null === $userAgent ? null : mb_substr($userAgent, 0, 255);
        $this->ipAddress = $ipAddress;
    }

    public function getSeries(): string
    {
        return $this->series;
    }

    public function getTokenValue(): string
    {
        return $this->tokenValue;
    }

    public function getUserIdentifier(): string
    {
        return $this->userIdentifier;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): \DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function refresh(string $tokenValue, \DateTimeImmutable $lastUsedAt): void
    {
        $this->tokenValue = $tokenValue;
        $this->lastUsedAt = $lastUsedAt;
    }
}
