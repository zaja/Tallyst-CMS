<?php

namespace Tallyst\FormBuilder\Entity;

use App\Entity\Member;
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
#[ORM\Index(name: 'idx_order_customer_email', columns: ['customer_email'])]
#[ORM\HasLifecycleCallbacks]
class Order
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_FULFILLED = 'fulfilled';
    public const STATUS_REFUNDED = 'refunded';

    /**
     * A checkout that never completed — a declined card, or a window closed and never returned to.
     *
     * ⚠ IT EXISTS BECAUSE `pending` MEANT TWO THINGS AT ONCE: "the payment is going through, wait"
     * and "this never happened". Nothing told them apart, nothing ever closed the second, and the
     * thank-you page went on promising a confirmation e-mail that was never coming. Measured on the
     * dev database before this: a THIRD of all orders were pending, none younger than a month.
     *
     * ⚠ THESE ORDERS ARE NEVER DELETED. The owner has to be able to see how many people drop out —
     * that number is a fact about the shop, not litter.
     */
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Marks orders placed through a demo form, so the uninstaller can remove exactly the demo set. */
    #[ORM\Column(options: ['default' => false])]
    private bool $isDemo = false;

    /**
     * The form this order was placed through — kept as "where it came from", NOT as the source of the
     * order's own facts (those are the snapshot columns below). NULLABLE + ON DELETE SET NULL: a deleted
     * form must never take the financial record with it. (The admin delete action is additionally blocked
     * by FormDeletionGuard; this is the database-level belt behind that suspender, and it also covers the
     * paths the guard deliberately does not — the demo uninstaller and any direct em->remove().)
     */
    #[ORM\ManyToOne(targetEntity: FormDefinition::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?FormDefinition $form = null;

    #[ORM\ManyToOne(targetEntity: FormSubmission::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?FormSubmission $submission = null;

    /**
     * Which customer account this sale belongs to, once somebody has proven they hold the address.
     *
     * ⚠ A HARD link, deliberately, rather than matching on e-mail at display time: the account's
     * address can change, and a soft match would silently drop every earlier purchase the day it did.
     *
     * ⚠ NULL is a normal, expected state, not a defect — it means "nobody has proven this address
     * yet". The order simply waits, and is found by address the moment someone does.
     *
     * ⚠ ON DELETE SET NULL: deleting a customer account must never delete sales records. The order
     * history is the SITE OWNER's business ledger; a buyer closing their account cannot take it with
     * them. Same reasoning as the form link above.
     */
    #[ORM\ManyToOne(targetEntity: Member::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Member $member = null;

    // --- Historical snapshots taken ONCE, at checkout. Same rule as variantLabel/shippingLabel/taxName/
    //     taxRate: what was true when the money moved, written once and never refreshed afterwards. They
    //     exist so an order stays a complete record after the form (or the submission) is gone or edited.
    //     ⚠ Written at checkout; nothing READS them yet — the read sites still go through the live form.

    /**
     * SNAPSHOT of the form's name at purchase time — the product the buyer actually bought. Written once
     * at checkout and never refreshed, so renaming (or deleting) the form can never rewrite history on an
     * order that is already paid. Null only for rows created before this column existed and whose form was
     * already gone at migration time.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $productName = null;

    /**
     * SNAPSHOT of the whole submission payload at purchase time (FormSubmission::$data verbatim, INCLUDING
     * the ship_* delivery-address keys). Written once at checkout and never refreshed. Exists because the
     * submission is reachable only through a nullable FK (SET NULL) and is itself CASCADE-deleted with the
     * form — without this copy, deleting a form silently empties the buyer's details on every order it ever
     * took. Null = no snapshot (pre-column row with no surviving submission); an empty array is a real,
     * meaningful value (a form with no fields).
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(name: 'submission_data', type: 'json', nullable: true)]
    private ?array $submissionData = null;

    /**
     * SNAPSHOT of whether this purchase went through a Merchant-of-Record form (form type `digital_mor`) —
     * i.e. whether someone else was the legal seller. Written once at checkout, exactly like paymentMode
     * records test/live: a historical fact about this payment, not a lookup of how the form is configured
     * today. Defaults to false so an ordinary self-billed order needs no special handling.
     */
    #[ORM\Column(name: 'is_merchant_of_record', options: ['default' => false])]
    private bool $isMerchantOfRecord = false;

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
     * right unit is charged. Null for self-billed / legacy orders. LIVE: written by
     * FormSubmitController::startCheckout from the buyer's chosen unit, read by DodoProcessor::createCheckout.
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

    /**
     * SNAPSHOT of the page the buyer was on when they started this checkout — a site-relative path.
     *
     * ⚠ It is a SNAPSHOT for the same reason as productName, not a convenience. A form has no URL of
     * its own; it lives embedded in a page through `[form id=N]`, and nothing else records WHICH page.
     * Without this an unfinished checkout could only ever be offered "go to the home page and find it
     * again", which is not an offer to finish a purchase.
     *
     * ⚠ Site-relative and validated on write (leading `/`, never `//`), because it is later put in an
     * e-mail as a link — an absolute or protocol-relative value here would be an open redirect posted
     * to the buyer under the shop's name.
     */
    #[ORM\Column(name: 'return_path', length: 255, nullable: true)]
    private ?string $returnPath = null;

    /**
     * When this order was declared not completed — and it is NEVER cleared afterwards.
     *
     * ⚠ IT SURVIVES A LATE PAYMENT ON PURPOSE. A slow method (bank transfer, SEPA) can settle after
     * the deadline has already closed the order; the money then wins and the status goes back to
     * paid, but the fact that the buyer had abandoned it stays visible to the owner. Without this
     * column that history would vanish the moment the webhook landed, and the owner would lose the
     * only evidence of how many people leave and how many come back.
     */
    #[ORM\Column(name: 'abandoned_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $abandonedAt = null;

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

    public function getMember(): ?Member
    {
        return $this->member;
    }

    public function setMember(?Member $member): static
    {
        $this->member = $member;

        return $this;
    }

    public function getProductName(): ?string
    {
        return $this->productName;
    }

    public function setProductName(?string $productName): static
    {
        $this->productName = $productName;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getSubmissionData(): ?array
    {
        return $this->submissionData;
    }

    /** @param array<string, mixed>|null $submissionData */
    public function setSubmissionData(?array $submissionData): static
    {
        $this->submissionData = $submissionData;

        return $this;
    }

    public function isMerchantOfRecord(): bool
    {
        return $this->isMerchantOfRecord;
    }

    public function setIsMerchantOfRecord(bool $isMerchantOfRecord): static
    {
        $this->isMerchantOfRecord = $isMerchantOfRecord;

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

    public function getReturnPath(): ?string
    {
        return $this->returnPath;
    }

    /** ⚠ Refuses anything that is not a site-relative path — see the property docblock. */
    public function setReturnPath(?string $returnPath): static
    {
        $returnPath = null === $returnPath ? null : trim($returnPath);
        $safe = null !== $returnPath
            && str_starts_with($returnPath, '/')
            && !str_starts_with($returnPath, '//');

        $this->returnPath = $safe ? $returnPath : null;

        return $this;
    }

    public function getAbandonedAt(): ?\DateTimeImmutable
    {
        return $this->abandonedAt;
    }

    public function setAbandonedAt(?\DateTimeImmutable $abandonedAt): static
    {
        $this->abandonedAt = $abandonedAt;

        return $this;
    }

    /** True once this order has ever been declared not completed — even if it was paid afterwards. */
    public function wasAbandoned(): bool
    {
        return null !== $this->abandonedAt;
    }

    public function isFailed(): bool
    {
        return self::STATUS_FAILED === $this->status;
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

    /**
     * The product this order is FOR, as shown to a human (admin screens, order e-mails).
     *
     * Reads the snapshot first, so renaming or deleting the form can never rewrite what an already-paid
     * order says it sold. The live form is only a fallback for a row whose snapshot is missing — in
     * practice only an order that had already lost its form before the snapshot column existed (the
     * backfill filled every row that still had one), which is why the last resort is '-'.
     *
     * ⚠ This is the DISPLAY resolver. getProductName() is the raw snapshot and stays that way; use this
     * one wherever a name is rendered or mailed, so the fallback chain lives in exactly one place.
     */
    public function getProductLabel(): string
    {
        $snapshot = $this->productName;
        if (null !== $snapshot && '' !== $snapshot) {
            return $snapshot;
        }

        $live = $this->form?->getName();

        return (null !== $live && '' !== $live) ? $live : '-';
    }

    /**
     * The form this order came from, for the admin's "where did this come from" line — NOT the source of
     * the order's own facts. An em-dash (the class's existing "not applicable" marker, as in
     * getNetFormatted/getShippingFormatted) when the form has since been deleted: the order is intact,
     * only its origin is gone, and that is a normal state rather than an error.
     */
    public function getSourceFormLabel(): string
    {
        $name = $this->form?->getName();

        return (null !== $name && '' !== $name) ? $name : '—';
    }

    /**
     * The buyer's submitted values as this order recorded them.
     *
     * Snapshot first, live submission as a fallback, empty as the last resort. ⚠ `??` is deliberate: an
     * EMPTY snapshot array is a real answer ("this form had no fields / nothing was filled in") and must
     * NOT fall through to the submission — only a NULL snapshot (never taken) does. The fallback matters
     * because the submission hangs off a nullable FK and is CASCADE-deleted with the form, so without the
     * snapshot a deleted form silently empties the buyer's details on every order it ever took.
     *
     * @return array<string, mixed>
     */
    private function submittedValues(): array
    {
        return $this->submissionData ?? $this->submission?->getData() ?? [];
    }

    /**
     * The buyer's submitted fields as key => value, WITHOUT the ship_* delivery address (which is
     * rendered on its own, formatted). Structured rather than the flattened string the CSV wants,
     * because the buyer's own page shows them as a list.
     *
     * @return array<string, string>
     */
    public function getSubmittedFields(): array
    {
        $shipKeys = array_keys(ShippingAddress::FIELDS);
        $fields = [];
        foreach ($this->submittedValues() as $key => $value) {
            if (\in_array($key, $shipKeys, true)) {
                continue;
            }
            $fields[(string) $key] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        return $fields;
    }

    /** Human-readable dump of the submitted form data (ALL keys, incl. ship_*), for the CSV export. */
    public function getSubmissionSummary(): string
    {
        $data = $this->submittedValues();
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
        $data = $this->submittedValues();
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
        $data = $this->submittedValues();
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
