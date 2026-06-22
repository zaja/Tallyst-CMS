<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Scheb\TwoFactorBundle\Model\BackupCodeInterface;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface as TotpTwoFactorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Back-office user. Authentication identifier is the email address.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface, TotpTwoFactorInterface, BackupCodeInterface
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    /** @var string[] */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    /** Hashed password. */
    #[ORM\Column]
    private string $password = '';

    /** TOTP shared secret (base32). Set during enrolment; 2FA is active only once confirmed. */
    #[ORM\Column(name: 'totp_secret', length: 255, nullable: true)]
    private ?string $totpSecret = null;

    /** Confirm-before-activate: true only after a valid code proves the secret was scanned. */
    #[ORM\Column(name: 'totp_enabled')]
    private bool $totpEnabled = false;

    /** One-time recovery codes, stored HASHED (sha256 of high-entropy codes). */
    #[ORM\Column(name: 'backup_codes', type: Types::JSON, nullable: true)]
    private ?array $backupCodes = null;

    public function __construct(string $email = '')
    {
        $this->email = $email;
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

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * @return string[]
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // Guarantee every user at least has ROLE_USER.
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * @param string[] $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * No sensitive temporary data stored on the entity.
     */
    public function eraseCredentials(): void
    {
    }

    // --- TOTP two-factor (scheb/2fa) ---

    public function isTotpAuthenticationEnabled(): bool
    {
        // Confirm-before-activate: a stored-but-unconfirmed secret is INERT, so a user mid-
        // enrolment (or an existing user with no secret) logs in normally — never locked out.
        return $this->totpEnabled && null !== $this->totpSecret;
    }

    public function getTotpAuthenticationUsername(): string
    {
        return $this->email;
    }

    public function getTotpAuthenticationConfiguration(): ?TotpConfigurationInterface
    {
        if (null === $this->totpSecret) {
            return null;
        }

        // Bundle defaults (matches authenticator apps): SHA1, 30s period, 6 digits.
        return new TotpConfiguration($this->totpSecret, TotpConfiguration::ALGORITHM_SHA1, 30, 6);
    }

    public function getTotpSecret(): ?string
    {
        return $this->totpSecret;
    }

    public function setTotpSecret(?string $totpSecret): static
    {
        $this->totpSecret = $totpSecret;

        return $this;
    }

    public function setTotpEnabled(bool $totpEnabled): static
    {
        $this->totpEnabled = $totpEnabled;

        return $this;
    }

    // --- Backup codes (scheb/2fa) — stored hashed; high-entropy so sha256 is sufficient ---

    public function isBackupCode(string $code): bool
    {
        $hash = self::hashBackupCode($code);
        foreach ($this->backupCodes ?? [] as $stored) {
            if (hash_equals((string) $stored, $hash)) {
                return true;
            }
        }

        return false;
    }

    public function invalidateBackupCode(string $code): void
    {
        $hash = self::hashBackupCode($code);
        $this->backupCodes = array_values(array_filter(
            $this->backupCodes ?? [],
            static fn ($stored): bool => !hash_equals((string) $stored, $hash),
        ));
    }

    /** @param string[] $plainCodes — store their hashes; the plaintext is shown to the user once. */
    public function setBackupCodesFromPlain(array $plainCodes): static
    {
        $this->backupCodes = array_map(self::hashBackupCode(...), $plainCodes);

        return $this;
    }

    /** @return string[]|null */
    public function getBackupCodes(): ?array
    {
        return $this->backupCodes;
    }

    public function setBackupCodes(?array $backupCodes): static
    {
        $this->backupCodes = $backupCodes;

        return $this;
    }

    public static function hashBackupCode(string $code): string
    {
        return hash('sha256', $code);
    }

    public function __toString(): string
    {
        return $this->email;
    }
}
