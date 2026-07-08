<?php

namespace Tallyst\FormBuilder\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Tallyst\FormBuilder\Repository\DodoPendingLicenseRepository;

/**
 * A licence key that arrived (via a Dodo entitlement_grant.created webhook) BEFORE its payment.succeeded
 * — so no order was yet findable by payment_id. Webhooks are at-least-once and unordered, so this holds
 * the orphaned licence keyed by payment_id until the paid webhook lands and claims it.
 *
 * This makes ordering deterministic WITHOUT retries: the entitlement webhook always acks 200 (storing
 * here when the order isn't found yet); the later paid webhook, after it sets the order's payment_id,
 * looks here and attaches the licence. UNIQUE(payment_id) makes a duplicate entitlement a no-op.
 */
#[ORM\Entity(repositoryClass: DodoPendingLicenseRepository::class)]
#[ORM\Table(name: 'dodo_pending_license')]
#[ORM\UniqueConstraint(name: 'uniq_dodo_pending_payment', columns: ['payment_id'])]
class DodoPendingLicense
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $paymentId;

    #[ORM\Column(length: 255)]
    private string $licenseKey;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $paymentId, string $licenseKey)
    {
        $this->paymentId = $paymentId;
        $this->licenseKey = $licenseKey;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPaymentId(): string
    {
        return $this->paymentId;
    }

    public function getLicenseKey(): string
    {
        return $this->licenseKey;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
