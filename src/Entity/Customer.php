<?php

namespace App\Entity;

use App\Repository\CustomerRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A buyer who has PROVEN they hold their e-mail address, by following a login link.
 *
 * ⚠ A Customer is a CLAIM OF IDENTITY, and that is why one is never created at purchase time —
 * only when someone confirms a login link sent to that address. A record created without that
 * proof would mean "account" no longer implies "somebody proved this", and everything later hung
 * off it (delivery, licence keys, support) would inherit a guarantee it does not have.
 *
 * ⚠ A Customer is NOT a User. Users are staff and live behind the ^/admin firewall, which is
 * fail-open: any new admin screen is reachable by an editor unless someone remembers to lock it.
 * Keeping buyers in a separate entity, provider and firewall means one forgotten lock can never
 * let a buyer into the back-office. Deliberately implements UserInterface WITHOUT
 * PasswordAuthenticatedUserInterface — there is no customer password anywhere in the system.
 */
#[ORM\Entity(repositoryClass: CustomerRepository::class)]
#[ORM\Table(name: 'customer')]
class Customer implements UserInterface
{
    public const ROLE = 'ROLE_CUSTOMER';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** 191 so the unique index fits MySQL's utf8mb4 key limit, like Setting::$name. */
    #[ORM\Column(length: 191, unique: true)]
    private string $email;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    public function __construct(string $email)
    {
        $this->email = $email;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function touchLastLogin(): static
    {
        $this->lastLoginAt = new \DateTimeImmutable();

        return $this;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        // Flat and fixed: a customer is a customer. No hierarchy, and nothing that could ever
        // resolve to an admin role.
        return [self::ROLE];
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function eraseCredentials(): void
    {
        // No credentials are ever held in memory — login is by e-mail link only.
    }

    public function __toString(): string
    {
        return $this->email;
    }
}
