<?php

namespace Tallyst\FormBuilder\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Tallyst\FormBuilder\Repository\FormDefinitionRepository;
use Tallyst\FormBuilder\Validator\MorProviderMatchesType;

/**
 * An admin-built form, stored AS DATA (not a compile-time Symfony form). Rendered
 * and validated dynamically at runtime from its FormField rows.
 */
#[ORM\Entity(repositoryClass: FormDefinitionRepository::class)]
#[ORM\Table(name: 'fb_form')]
#[ORM\HasLifecycleCallbacks]
#[MorProviderMatchesType]
class FormDefinition
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    /**
     * taxRateKey sentinel = "no tax on this form" (an explicit opt-out, distinct from NULL = default rate).
     * Never collides with a real catalog key (those are 8-hex). See PLAN-FAZA-3-POREZ.md §3.
     */
    public const TAX_NONE = 'none';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Marks content seeded by app:demo:seed, so the uninstaller can remove exactly the demo set. */
    #[ORM\Column(options: ['default' => false])]
    private bool $isDemo = false;

    #[ORM\Column(length: 191)]
    private string $name = '';

    #[ORM\Column(length: 191, unique: true)]
    private string $slug = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_DRAFT;

    /**
     * The EXPLICIT "what is this form" decision (Faza 4) — the remembered type set by the create wizard,
     * replacing the old guessing. ⚠ KOMAD 1: stored but NOT yet consumed (isProduct()/isMerchantOfRecordForm()
     * still guess from price/Dodo). Wiring consumers to read this is KOMAD 2. Defaults to MESSAGES (a free
     * form) so a bare `new FormDefinition()` is inert. See PLAN-FAZA-4-WIZARD.md §2.
     */
    #[ORM\Column(length: 16, enumType: FormType::class, options: ['default' => FormType::MESSAGES->value])]
    private FormType $formType = FormType::MESSAGES;

    /**
     * Price in the currency's MINOR units (e.g. cents), as an integer — money never
     * touches float. A form with a positive priceMinor is a product (page-as-product).
     */
    #[ORM\Column(nullable: true)]
    private ?int $priceMinor = null;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $currency = null;

    /**
     * Per-product payment-method limit (provider names, e.g. ["stripe","paypal"]). NULL/empty =
     * all configured providers. The checkout offers configured ∩ this.
     *
     * @var string[]|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $allowedPaymentMethods = null;

    /**
     * The shipping-method KEYS (from the ShippingCatalog) this form offers at checkout. NULL/empty = no
     * shipping. Stores ONLY the stable catalog keys — never a price (the catalog is authoritative) — the
     * same way allowedPaymentMethods stores provider names. The offer is filtered against the LIVE catalog
     * at render/checkout, so a deleted method silently drops. See PLAN-FAZA-1-DOSTAVA.md §4.
     *
     * @var string[]|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $shippingMethods = null;

    /**
     * The ISO 3166-1 alpha-2 country codes this form is allowed to ship to (Faza 2 — a per-form gate).
     * NULL/empty = ships EVERYWHERE (the gate is opt-in, backward-compatible; a new form starts empty).
     * Stored as stable UPPERCASE codes, validated against the standard list (symfony/intl); mirrors
     * shippingMethods. Only enforced when the form offers delivery AND is not a MoR form. See
     * PLAN-FAZA-2-ZEMLJE.md §4.
     *
     * @var string[]|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $allowedShippingCountries = null;

    /**
     * Which named tax rate (from the TaxCatalog) applies to THIS form (Faza 3). Three states:
     *   NULL           → the catalog's DEFAULT rate (backward-compat: every existing form has null → charges
     *                    identically to before, when there was one global rate);
     *   '<key>'        → that specific catalog rate;
     *   self::TAX_NONE → an explicit "no tax" opt-out.
     * A deleted key falls back to the default at resolution time (TaxCalculator::forForm, Faza 3 Komad 4),
     * so a stale key never breaks checkout. Ignored entirely on a MoR (Dodo) form (the MoR owns tax).
     * See PLAN-FAZA-3-POREZ.md §3, §4.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $taxRateKey = null;

    /**
     * The Dodo (Merchant-of-Record) product this form sells against. Per-form so each product maps to
     * its own Dodo product (with its own tax category / entitlement config in the Dodo dashboard).
     * NULL = no Dodo product linked → a Dodo checkout for this form is refused (never a dead checkout).
     * Set via SQL for now — the edit-form UI is Phase 3.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $dodoProductId = null;

    /**
     * WHICH Merchant-of-Record provider this form sells through (Faza 5) — a payment-provider NAME from the
     * registry (today only 'dodo'; Paddle/Lemon Squeezy later). A STRING (registry key), NOT an enum, because
     * providers live in the plugin registry (dynamic) — same shape as `Order.provider`/`allowedPaymentMethods`.
     * Meaningful ONLY on a `digital_mor` form: there it must be a registered MoR provider; on any other type
     * it MUST be null. Replaces deriving "which MoR" from `dodoProductId` (which stays the Dodo PRODUCT id,
     * no longer a provider proxy). ⚠ KOMAD 1: stored (wizard + backfill set it) but NOT yet consumed —
     * offeredMethods/the picker still treat Dodo as the sole MoR until KOMAD 2. See PLAN-FAZA-5-MOR-PROVIDER.md.
     */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $morProvider = null;

    /**
     * Price variants (or-or): a flat list of {label, priceMinor}. When non-empty, the buyer picks one
     * and its price is used INSTEAD of priceMinor; empty/null = fixed priceMinor. Currency is shared
     * (product-level). Single dimension — no matrix.
     *
     * @var array<int, array{label: string, priceMinor: int}>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $variants = null;

    /**
     * Submission-notification config for FREE forms (priced forms use the order/fulfilment
     * mails). When enabled, a notification e-mail is sent on each valid submit.
     */
    #[ORM\Column(name: 'notify_enabled')]
    private bool $notifyEnabled = false;

    /** One or more e-mails, comma/semicolon separated. */
    #[ORM\Column(name: 'notify_recipient', length: 255, nullable: true)]
    private ?string $notifyRecipient = null;

    /** Optional override; empty falls back to "Nova prijava: <form name>". */
    #[ORM\Column(name: 'notify_subject', length: 255, nullable: true)]
    private ?string $notifySubject = null;

    /** @var Collection<int, FormField> */
    #[ORM\OneToMany(targetEntity: FormField::class, mappedBy: 'form', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $fields;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->fields = new ArrayCollection();
    }

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

    public function __toString(): string
    {
        return $this->name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

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

    public function isPublished(): bool
    {
        return self::STATUS_PUBLISHED === $this->status;
    }

    public function getFormType(): FormType
    {
        return $this->formType;
    }

    public function setFormType(FormType $formType): static
    {
        $this->formType = $formType;

        return $this;
    }

    /**
     * Type-derived helpers (Faza 4). ⚠ KOMAD 1: these are NEW and not yet wired into the guessing methods
     * (isProduct()/isMerchantOfRecordForm() still derive from price/Dodo) — that switch is KOMAD 2. Provided
     * now so the model + tests are complete.
     */
    public function isProductType(): bool
    {
        return $this->formType->isProduct();
    }

    public function isMerchantOfRecordType(): bool
    {
        return $this->formType->isMerchantOfRecord();
    }

    public function getPriceMinor(): ?int
    {
        return $this->priceMinor;
    }

    public function setPriceMinor(?int $priceMinor): static
    {
        $this->priceMinor = $priceMinor;

        return $this;
    }

    /**
     * A product form (sells something → its submission goes through payment). Faza 4 KOMAD 2: this now
     * reads the EXPLICIT formType (the remembered decision), not the old price/variants guess. A form's
     * "is it a product" is a stored fact, so a messages form with a stray price stays free, and a product
     * form with no price yet is still a product. The actual charged amount is still computed from
     * priceMinor/variants (variantAt) — this only decides free-vs-checkout. See PLAN-FAZA-4-WIZARD.md §2.
     */
    public function isProduct(): bool
    {
        return $this->formType->isProduct();
    }

    /** @return array<int, array{label: string, priceMinor: int}>|null */
    public function getVariants(): ?array
    {
        return $this->variants;
    }

    /**
     * Store variants, dropping fully-empty rows (prototype/blank) and coercing priceMinor to int.
     * Half-filled rows (label xor price) are KEPT so validation can flag them.
     *
     * @param array<int, array{label?: string, priceMinor?: mixed}>|null $variants
     */
    public function setVariants(?array $variants): static
    {
        $clean = [];
        foreach ($variants ?? [] as $v) {
            $label = trim((string) ($v['label'] ?? ''));
            $price = $v['priceMinor'] ?? null;
            if ('' === $label && (null === $price || '' === $price || 0 === (int) $price)) {
                continue; // fully empty row — not a variant
            }
            $clean[] = ['label' => $label, 'priceMinor' => null === $price || '' === $price ? 0 : (int) $price];
        }

        $this->variants = [] === $clean ? null : array_values($clean);

        return $this;
    }

    /** Whether this product is variant-priced (≥1 complete variant). */
    public function hasVariants(): bool
    {
        foreach ($this->variants ?? [] as $v) {
            if ('' !== ($v['label'] ?? '') && (int) ($v['priceMinor'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The variant at $index, or null if out of range — the server-side selection gate.
     *
     * @return array{label: string, priceMinor: int}|null
     */
    public function variantAt(int $index): ?array
    {
        $list = array_values($this->variants ?? []);

        return ($index >= 0 && isset($list[$index])) ? $list[$index] : null;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    /** @return string[]|null */
    public function getAllowedPaymentMethods(): ?array
    {
        return $this->allowedPaymentMethods;
    }

    /** @param string[]|null $allowedPaymentMethods */
    public function setAllowedPaymentMethods(?array $allowedPaymentMethods): static
    {
        $this->allowedPaymentMethods = $allowedPaymentMethods ?: null;

        return $this;
    }

    /** @return string[]|null */
    public function getShippingMethods(): ?array
    {
        return $this->shippingMethods;
    }

    /** @param string[]|null $shippingMethods */
    public function setShippingMethods(?array $shippingMethods): static
    {
        $this->shippingMethods = $shippingMethods ?: null;

        return $this;
    }

    /**
     * Whether this form offers at least one shipping method (by key). The concrete offer is filtered
     * against the live catalog at render/checkout (ShippingCatalog::offeredFor) — a form whose only
     * selected method was later deleted still returns true here but offers nothing downstream.
     */
    public function hasShipping(): bool
    {
        return null !== $this->shippingMethods && [] !== $this->shippingMethods;
    }

    /** @return string[]|null */
    public function getAllowedShippingCountries(): ?array
    {
        return $this->allowedShippingCountries;
    }

    /**
     * @param string[]|null $allowedShippingCountries stored as valid UPPERCASE alpha-2 codes; invalid /
     *                                                unknown codes are dropped (defense — never persist junk)
     */
    public function setAllowedShippingCountries(?array $allowedShippingCountries): static
    {
        $clean = [];
        foreach ($allowedShippingCountries ?? [] as $code) {
            $code = strtoupper(trim((string) $code));
            if ('' !== $code && Countries::exists($code) && !in_array($code, $clean, true)) {
                $clean[] = $code;
            }
        }

        $this->allowedShippingCountries = [] === $clean ? null : $clean;

        return $this;
    }

    /**
     * Whether this form ships to the given ISO alpha-2 country code. An EMPTY allow-list means
     * "everywhere" (the gate is opt-in), so it returns true. Case-insensitive.
     */
    public function allowsCountry(string $code): bool
    {
        if (null === $this->allowedShippingCountries || [] === $this->allowedShippingCountries) {
            return true;
        }

        return in_array(strtoupper(trim($code)), $this->allowedShippingCountries, true);
    }

    public function getTaxRateKey(): ?string
    {
        return $this->taxRateKey;
    }

    /**
     * Store null for an empty/absent choice (= the default rate) so a blank submit and an untouched form
     * are identical; a real 8-hex key or the TAX_NONE sentinel are kept verbatim.
     */
    public function setTaxRateKey(?string $taxRateKey): static
    {
        $taxRateKey = null === $taxRateKey ? null : trim($taxRateKey);
        $this->taxRateKey = ('' === $taxRateKey) ? null : $taxRateKey;

        return $this;
    }

    public function getDodoProductId(): ?string
    {
        return $this->dodoProductId;
    }

    public function setDodoProductId(?string $dodoProductId): static
    {
        $this->dodoProductId = $dodoProductId ?: null;

        return $this;
    }

    public function getMorProvider(): ?string
    {
        return $this->morProvider;
    }

    /** Store null for an empty value; a real provider name is kept verbatim (validated by MorProviderMatchesType). */
    public function setMorProvider(?string $morProvider): static
    {
        $morProvider = null === $morProvider ? null : trim($morProvider);
        $this->morProvider = ('' === $morProvider) ? null : $morProvider;

        return $this;
    }

    public function isNotifyEnabled(): bool
    {
        return $this->notifyEnabled;
    }

    public function setNotifyEnabled(bool $notifyEnabled): static
    {
        $this->notifyEnabled = $notifyEnabled;

        return $this;
    }

    public function getNotifyRecipient(): ?string
    {
        return $this->notifyRecipient;
    }

    public function setNotifyRecipient(?string $notifyRecipient): static
    {
        $this->notifyRecipient = $notifyRecipient;

        return $this;
    }

    /** @return list<string> recipients split from the comma/semicolon list (trimmed, non-empty) */
    public function getNotifyRecipientList(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            preg_split('/[,;]/', (string) $this->notifyRecipient) ?: [],
        )));
    }

    public function getNotifySubject(): ?string
    {
        return $this->notifySubject;
    }

    public function setNotifySubject(?string $notifySubject): static
    {
        $this->notifySubject = $notifySubject;

        return $this;
    }

    /**
     * When notifications are on, require at least one recipient and reject any malformed
     * e-mail — so the admin gets feedback instead of the notifier silently skipping.
     */
    #[Assert\Callback]
    public function validateNotification(ExecutionContextInterface $context): void
    {
        if (!$this->notifyEnabled) {
            return;
        }

        // buildViolation messages translate via the `validators` domain (keys; %email% as a param).
        $recipients = $this->getNotifyRecipientList();
        if ([] === $recipients) {
            $context->buildViolation('validation.form.notify_recipient_required')
                ->atPath('notifyRecipient')->addViolation();

            return;
        }

        foreach ($recipients as $recipient) {
            if (false === filter_var($recipient, \FILTER_VALIDATE_EMAIL)) {
                $context->buildViolation('validation.form.notify_email_invalid')
                    ->setParameter('%email%', $recipient)
                    ->atPath('notifyRecipient')->addViolation();
            }
        }
    }

    /** Each variant must have a label AND a positive price (a half-filled row is an error). */
    #[Assert\Callback]
    public function validateVariants(ExecutionContextInterface $context): void
    {
        foreach (array_values($this->variants ?? []) as $i => $v) {
            $label = trim((string) ($v['label'] ?? ''));
            $price = (int) ($v['priceMinor'] ?? 0);
            if ('' === $label || $price <= 0) {
                $context->buildViolation('validation.form.variant_incomplete')
                    ->atPath('variants['.$i.']')->addViolation();
            }
        }
    }

    /** @return Collection<int, FormField> */
    public function getFields(): Collection
    {
        return $this->fields;
    }

    public function addField(FormField $field): static
    {
        if (!$this->fields->contains($field)) {
            $this->fields->add($field);
            $field->setForm($this);
        }

        return $this;
    }

    public function removeField(FormField $field): static
    {
        if ($this->fields->removeElement($field)) {
            if ($field->getForm() === $this) {
                $field->setForm(null);
            }
        }

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
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
