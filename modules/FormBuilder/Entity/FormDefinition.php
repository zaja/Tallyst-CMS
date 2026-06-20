<?php

namespace Tallyst\FormBuilder\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
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
