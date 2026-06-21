<?php

namespace Tallyst\FormBuilder\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Tallyst\FormBuilder\Repository\FormDefinitionRepository;

/**
 * An admin-built form, stored AS DATA (not a compile-time Symfony form). Rendered
 * and validated dynamically at runtime from its FormField rows.
 */
#[ORM\Entity(repositoryClass: FormDefinitionRepository::class)]
#[ORM\Table(name: 'fb_form')]
#[ORM\HasLifecycleCallbacks]
class FormDefinition
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 191)]
    private string $name = '';

    #[ORM\Column(length: 191, unique: true)]
    private string $slug = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_DRAFT;

    /**
     * Price in the currency's MINOR units (e.g. cents), as an integer — money never
     * touches float. A form with a positive priceMinor is a product (page-as-product).
     */
    #[ORM\Column(nullable: true)]
    private ?int $priceMinor = null;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $currency = null;

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

    public function getPriceMinor(): ?int
    {
        return $this->priceMinor;
    }

    public function setPriceMinor(?int $priceMinor): static
    {
        $this->priceMinor = $priceMinor;

        return $this;
    }

    /** A priced form is a product: its submission goes through payment. */
    public function isProduct(): bool
    {
        return null !== $this->priceMinor && $this->priceMinor > 0;
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

        $recipients = $this->getNotifyRecipientList();
        if ([] === $recipients) {
            $context->buildViolation('Unesi barem jednog primatelja kad je notifikacija uključena.')
                ->atPath('notifyRecipient')->addViolation();

            return;
        }

        foreach ($recipients as $recipient) {
            if (false === filter_var($recipient, \FILTER_VALIDATE_EMAIL)) {
                $context->buildViolation('Neispravna e-mail adresa: '.$recipient)
                    ->atPath('notifyRecipient')->addViolation();
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
}
