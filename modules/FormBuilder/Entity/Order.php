<?php

namespace Tallyst\FormBuilder\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Tallyst\FormBuilder\Repository\OrderRepository;
use Tallyst\FormBuilder\Service\ShippingAddress;

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

    /**
     * The Merchant-of-Record sellable-unit id the buyer chose (Faza 6) — the provider's own id (Dodo
     * product_id today; a GENERIC name so Paddle/LS reuse). Set at checkout from the chosen sellable unit;
     * DodoProcessor::createCheckout reads it (falling back to the form's legacy single dodoProductId) so the
     * right unit is charged. Null for self-billed / legacy orders. ⚠ KOMAD 1: column added, DORMANT — nobody
     * sets or reads it yet (the checkout wiring is KOMAD 4).
     */
    #[ORM\Column(name: 'provider_unit_id', length: 191, nullable: true)]
    private ?string $providerUnitId = null;

    // --- Shipping (Faza 1). Null when the form offered no delivery (or a MoR order — never shipped by
    // Tallyst). amountMinor INCLUDES the shipping amount; these record which method + how much of it.

    /** The chosen delivery method's label at order time (a snapshot — the catalog may change later). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $shippingLabel = null;

    /** The delivery amount (minor units, inclusive of tax) folded into amountMinor. */
    #[ORM\Column(nullable: true)]
    private ?int $shippingAmountMinor = null;

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

    // --- Phase 2: passive capture of what the provider reports (Dodo/MoR). All nullable, all additive.

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $customerName = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $customerPhone = null;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $invoiceUrl = null;

    /** Licence key delivered by the provider (Dodo entitlement). One per order in v1. Read-only mirror. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $licenseKey = null;

    // Provider-authoritative amounts (minor units). For a MoR order these carry the seller-of-record's
    // own tax/settlement figures; Tallyst's own tax columns (net/tax/rate) stay null for MoR by design.
    #[ORM\Column(nullable: true)]
    private ?int $dodoTaxMinor = null;

    #[ORM\Column(nullable: true)]
    private ?int $dodoTotalMinor = null;

    #[ORM\Column(nullable: true)]
    private ?int $dodoSettlementMinor = null;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $dodoSettlementCurrency = null;

    /** Raw passive provider fields we capture but don't promote to columns (customer_id, entitlement_id…). */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $providerMetadata = null;

    /**
     * When the customer confirmation + admin notice were sent (Faza 8 K2). Idempotency marker so the
     * fulfilment mail goes EXACTLY ONCE regardless of which trigger fires first — the entitlement
     * re-dispatch (licence just landed) or the grace-delayed dispatch (fallback). Null = not sent yet.
     * Gates ONLY the automatic handler; the admin "resend confirmation" always sends.
     */
    #[ORM\Column(name: 'confirmation_sent_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $confirmationSentAt = null;

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

    public function getProviderUnitId(): ?string
    {
        return $this->providerUnitId;
    }

    public function setProviderUnitId(?string $providerUnitId): static
    {
        $providerUnitId = null === $providerUnitId ? null : trim($providerUnitId);
        $this->providerUnitId = ('' === $providerUnitId) ? null : $providerUnitId;

        return $this;
    }

    public function getShippingLabel(): ?string
    {
        return $this->shippingLabel;
    }

    public function setShippingLabel(?string $shippingLabel): static
    {
        $this->shippingLabel = $shippingLabel;

        return $this;
    }

    public function getShippingAmountMinor(): ?int
    {
        return $this->shippingAmountMinor;
    }

    public function setShippingAmountMinor(?int $shippingAmountMinor): static
    {
        $this->shippingAmountMinor = $shippingAmountMinor;

        return $this;
    }

    /** Formatted shipping amount for the admin detail (string getter — EA TextField rejects raw ints). */
    public function getShippingFormatted(): string
    {
        return null === $this->shippingAmountMinor ? '—' : number_format($this->shippingAmountMinor / 100, 2, ',', '.');
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

    public function getCustomerName(): ?string
    {
        return $this->customerName;
    }

    public function setCustomerName(?string $customerName): static
    {
        $this->customerName = $customerName;

        return $this;
    }

    public function getCustomerPhone(): ?string
    {
        return $this->customerPhone;
    }

    public function setCustomerPhone(?string $customerPhone): static
    {
        $this->customerPhone = $customerPhone;

        return $this;
    }

    public function getInvoiceUrl(): ?string
    {
        return $this->invoiceUrl;
    }

    public function setInvoiceUrl(?string $invoiceUrl): static
    {
        $this->invoiceUrl = $invoiceUrl;

        return $this;
    }

    public function getLicenseKey(): ?string
    {
        return $this->licenseKey;
    }

    public function setLicenseKey(?string $licenseKey): static
    {
        $this->licenseKey = $licenseKey;

        return $this;
    }

    public function getDodoTaxMinor(): ?int
    {
        return $this->dodoTaxMinor;
    }

    public function setDodoTaxMinor(?int $dodoTaxMinor): static
    {
        $this->dodoTaxMinor = $dodoTaxMinor;

        return $this;
    }

    public function getDodoTotalMinor(): ?int
    {
        return $this->dodoTotalMinor;
    }

    public function setDodoTotalMinor(?int $dodoTotalMinor): static
    {
        $this->dodoTotalMinor = $dodoTotalMinor;

        return $this;
    }

    public function getDodoSettlementMinor(): ?int
    {
        return $this->dodoSettlementMinor;
    }

    public function setDodoSettlementMinor(?int $dodoSettlementMinor): static
    {
        $this->dodoSettlementMinor = $dodoSettlementMinor;

        return $this;
    }

    public function getDodoSettlementCurrency(): ?string
    {
        return $this->dodoSettlementCurrency;
    }

    public function setDodoSettlementCurrency(?string $dodoSettlementCurrency): static
    {
        $this->dodoSettlementCurrency = $dodoSettlementCurrency ? strtoupper($dodoSettlementCurrency) : null;

        return $this;
    }

    public function getProviderMetadata(): ?array
    {
        return $this->providerMetadata;
    }

    public function setProviderMetadata(?array $providerMetadata): static
    {
        $this->providerMetadata = $providerMetadata ?: null;

        return $this;
    }

    public function getConfirmationSentAt(): ?\DateTimeImmutable
    {
        return $this->confirmationSentAt;
    }

    public function setConfirmationSentAt(?\DateTimeImmutable $confirmationSentAt): static
    {
        $this->confirmationSentAt = $confirmationSentAt;

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

    /** True when this order carries provider-authoritative (MoR) figures — drives the "Merchant of Record" detail block. */
    public function hasProviderSettlement(): bool
    {
        return null !== $this->dodoTaxMinor || null !== $this->dodoSettlementMinor || null !== $this->dodoTotalMinor;
    }

    /** Formatted Dodo tax for the admin detail (string getter — EA TextField rejects raw ints). */
    public function getDodoTaxFormatted(): string
    {
        return null === $this->dodoTaxMinor ? '—' : number_format($this->dodoTaxMinor / 100, 2, ',', '.');
    }

    /** Formatted Dodo settlement (payout) with its own currency. */
    public function getDodoSettlementFormatted(): string
    {
        if (null === $this->dodoSettlementMinor) {
            return '—';
        }

        $amount = number_format($this->dodoSettlementMinor / 100, 2, ',', '.');

        return null !== $this->dodoSettlementCurrency ? $amount.' '.$this->dodoSettlementCurrency : $amount;
    }

    /** Human-readable dump of the submitted form data (ALL keys, incl. ship_*), for the CSV export. */
    public function getSubmissionSummary(): string
    {
        $data = $this->submission?->getData() ?? [];
        $lines = [];
        foreach ($data as $key => $value) {
            $lines[] = $key.': '.(is_array($value) ? implode(', ', $value) : (string) $value);
        }

        return implode("\n", $lines);
    }

    /**
     * The submitted form fields EXCLUDING the ship_* delivery address (which is shown on its own as a
     * formatted block). For the admin detail "Form data" section + the admin mail. Empty when the form
     * had only address fields.
     */
    public function getFormDataSummary(): string
    {
        $data = $this->submission?->getData() ?? [];
        $shipKeys = array_keys(ShippingAddress::FIELDS);
        $lines = [];
        foreach ($data as $key => $value) {
            if (\in_array($key, $shipKeys, true)) {
                continue; // the delivery address is rendered separately, formatted
            }
            $lines[] = $key.': '.(is_array($value) ? implode(', ', $value) : (string) $value);
        }

        return implode("\n", $lines);
    }

    /**
     * The delivery address as a formatted, multi-line mailing label read from the submission's ship_*
     * fields (name / street / postal + city / country), skipping empty lines. Empty when the order has
     * no delivery address. Used by the admin detail, the admin mail and the customer confirmation.
     */
    public function getShippingAddressFormatted(): string
    {
        $data = $this->submission?->getData() ?? [];
        $get = static fn (string $key): string => trim((string) ($data[$key] ?? ''));

        $cityLine = trim($get('ship_postal').' '.$get('ship_city'));
        $lines = array_filter([
            $get('ship_name'),
            $get('ship_address'),
            $cityLine,
            $get('ship_country'),
        ], static fn (string $line): bool => '' !== $line);

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
