<?php

namespace Tallyst\FormBuilder\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Tallyst\FormBuilder\Repository\OrderRepository;

/**
 * A payment order for a priced form submission (page-as-product). Its lifecycle is
 * driven by the "order" state machine (pending → paid → fulfilled → refunded).
 *
 * Money is stored as integer minor units (e.g. cents) — never float. `paid` is the
 * truth about money and is set ONLY by the verified Stripe webhook.
 */
#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: 'fb_order')]
#[ORM\HasLifecycleCallbacks]
class Order
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_FULFILLED = 'fulfilled';
    public const STATUS_REFUNDED = 'refunded';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Marks orders placed through a demo form, so the uninstaller can remove exactly the demo set. */
    #[ORM\Column(options: ['default' => false])]
    private bool $isDemo = false;

    #[ORM\ManyToOne(targetEntity: FormDefinition::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?FormDefinition $form = null;

    #[ORM\ManyToOne(targetEntity: FormSubmission::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?FormSubmission $submission = null;

    #[ORM\Column]
    private int $amountMinor = 0;

    #[ORM\Column(length: 3)]
    private string $currency = 'eur';

    /** The chosen price variant's label (for display in admin + mail); null for non-variant orders. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $variantLabel = null;

    // --- Tax recording (inclusive). amountMinor stays GROSS; these are derived/recorded only.
    // All null when tax was disabled at order time (so export distinguishes "no tax" from a real 0).

    #[ORM\Column(nullable: true)]
    private ?int $taxAmountMinor = null;

    #[ORM\Column(nullable: true)]
    private ?int $netAmountMinor = null;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    private ?string $taxRate = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $taxName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $customerCountry = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $customerIp = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $customerVatId = null;

    /** Unguessable token in the thank-you URL (?t=) so order pages can't be enumerated by sequential id. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $thankYouToken = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 20)]
    private string $provider = 'stripe';

    /** Provider mode at creation ('test'/'live') — a historical fact, so the dashboard deep-link
     *  doesn't depend on the current config. Null for pre-recording orders (graceful fallback). */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $paymentMode = null;

    /** Provider checkout session id — how the webhook finds this order. */
    #[ORM\Column(length: 255, nullable: true, unique: true)]
    private ?string $providerSessionId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $providerPaymentIntentId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $customerEmail = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\PrePersist]
    public function initTimestamps(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
        $this->updatedAt ??= new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function bumpUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getForm(): ?FormDefinition
    {
        return $this->form;
    }

    public function setForm(?FormDefinition $form): static
    {
        $this->form = $form;

        return $this;
    }

    public function getSubmission(): ?FormSubmission
    {
        return $this->submission;
    }

    public function setSubmission(?FormSubmission $submission): static
    {
        $this->submission = $submission;

        return $this;
    }

    public function getAmountMinor(): int
    {
        return $this->amountMinor;
    }

    public function setAmountMinor(int $amountMinor): static
    {
        $this->amountMinor = $amountMinor;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = strtolower($currency);

        return $this;
    }

    public function getVariantLabel(): ?string
    {
        return $this->variantLabel;
    }

    public function setVariantLabel(?string $variantLabel): static
    {
        $this->variantLabel = $variantLabel;

        return $this;
    }

    public function getTaxAmountMinor(): ?int
    {
        return $this->taxAmountMinor;
    }

    public function setTaxAmountMinor(?int $taxAmountMinor): static
    {
        $this->taxAmountMinor = $taxAmountMinor;

        return $this;
    }

    public function getNetAmountMinor(): ?int
    {
        return $this->netAmountMinor;
    }

    public function setNetAmountMinor(?int $netAmountMinor): static
    {
        $this->netAmountMinor = $netAmountMinor;

        return $this;
    }

    public function getTaxRate(): ?string
    {
        return $this->taxRate;
    }

    public function setTaxRate(?string $taxRate): static
    {
        $this->taxRate = $taxRate;

        return $this;
    }

    public function getTaxName(): ?string
    {
        return $this->taxName;
    }

    public function setTaxName(?string $taxName): static
    {
        $this->taxName = $taxName;

        return $this;
    }

    public function getCustomerCountry(): ?string
    {
        return $this->customerCountry;
    }

    public function setCustomerCountry(?string $customerCountry): static
    {
        $this->customerCountry = $customerCountry;

        return $this;
    }

    public function getCustomerIp(): ?string
    {
        return $this->customerIp;
    }

    public function setCustomerIp(?string $customerIp): static
    {
        $this->customerIp = $customerIp;

        return $this;
    }

    public function getCustomerVatId(): ?string
    {
        return $this->customerVatId;
    }

    public function setCustomerVatId(?string $customerVatId): static
    {
        $this->customerVatId = $customerVatId;

        return $this;
    }

    public function getThankYouToken(): ?string
    {
        return $this->thankYouToken;
    }

    public function setThankYouToken(?string $thankYouToken): static
    {
        $this->thankYouToken = $thankYouToken;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isPaid(): bool
    {
        return \in_array($this->status, [self::STATUS_PAID, self::STATUS_FULFILLED], true);
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): static
    {
        $this->provider = $provider;

        return $this;
    }

    public function getPaymentMode(): ?string
    {
        return $this->paymentMode;
    }

    public function setPaymentMode(?string $paymentMode): static
    {
        $this->paymentMode = $paymentMode;

        return $this;
    }

    public function getProviderSessionId(): ?string
    {
        return $this->providerSessionId;
    }

    public function setProviderSessionId(?string $providerSessionId): static
    {
        $this->providerSessionId = $providerSessionId;

        return $this;
    }

    public function getProviderPaymentIntentId(): ?string
    {
        return $this->providerPaymentIntentId;
    }

    public function setProviderPaymentIntentId(?string $providerPaymentIntentId): static
    {
        $this->providerPaymentIntentId = $providerPaymentIntentId;

        return $this;
    }

    public function getCustomerEmail(): ?string
    {
        return $this->customerEmail;
    }

    public function setCustomerEmail(?string $customerEmail): static
    {
        $this->customerEmail = $customerEmail;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Amount formatted from integer minor units, e.g. "34,00 EUR". */
    public function getAmountFormatted(): string
    {
        return number_format($this->amountMinor / 100, 2, ',', '.').' '.strtoupper($this->currency);
    }

    /** Formatted net/tax for the admin detail (string getters — EA TextField rejects raw ints). */
    public function getNetFormatted(): string
    {
        return null === $this->netAmountMinor ? '—' : number_format($this->netAmountMinor / 100, 2, ',', '.');
    }

    public function getTaxFormatted(): string
    {
        return null === $this->taxAmountMinor ? '—' : number_format($this->taxAmountMinor / 100, 2, ',', '.');
    }

    /** Human-readable dump of the submitted form data, for the admin detail view. */
    public function getSubmissionSummary(): string
    {
        $data = $this->submission?->getData() ?? [];
        $lines = [];
        foreach ($data as $key => $value) {
            $lines[] = $key.': '.(is_array($value) ? implode(', ', $value) : (string) $value);
        }

        return implode("\n", $lines);
    }

    public function isDemo(): bool
    {
        return $this->isDemo;
    }

    public function setIsDemo(bool $isDemo): static
    {
        $this->isDemo = $isDemo;

        return $this;
    }
}
