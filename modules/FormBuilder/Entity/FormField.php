<?php

namespace Tallyst\FormBuilder\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Tallyst\FormBuilder\Repository\FormFieldRepository;

/**
 * One field of a form, stored as data. `conditions` holds the visibility rules
 * (single source of truth) evaluated identically on client and server.
 */
#[ORM\Entity(repositoryClass: FormFieldRepository::class)]
#[ORM\Table(name: 'fb_field')]
class FormField
{
    public const TYPE_TEXT = 'text';
    public const TYPE_EMAIL = 'email';
    public const TYPE_TEXTAREA = 'textarea';
    public const TYPE_NUMBER = 'number';
    public const TYPE_SELECT = 'select';
    public const TYPE_CHECKBOX = 'checkbox';
    public const TYPE_RADIO = 'radio';

    public const TYPES = [
        self::TYPE_TEXT,
        self::TYPE_EMAIL,
        self::TYPE_TEXTAREA,
        self::TYPE_NUMBER,
        self::TYPE_SELECT,
        self::TYPE_CHECKBOX,
        self::TYPE_RADIO,
    ];

    /** Types that carry a list of options. */
    public const TYPES_WITH_OPTIONS = [self::TYPE_SELECT, self::TYPE_RADIO];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: FormDefinition::class, inversedBy: 'fields')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?FormDefinition $form = null;

    #[ORM\Column(length: 20)]
    private string $type = self::TYPE_TEXT;

    #[ORM\Column(length: 191)]
    private string $label = '';

    /** Machine name used in submitted data and referenced by conditions. Unique per form. */
    #[ORM\Column(name: 'field_key', length: 100)]
    private string $key = '';

    #[ORM\Column(length: 191, nullable: true)]
    private ?string $placeholder = null;

    #[ORM\Column]
    private bool $required = false;

    /**
     * Options for select/radio, as a list of strings (value === label in pass 1).
     *
     * @var string[]
     */
    #[ORM\Column(type: Types::JSON)]
    private array $options = [];

    #[ORM\Column]
    private int $position = 0;

    /**
     * Visibility rules. Shape (single source of truth, see ConditionEvaluator):
     *   { "action": "show|hide", "match": "all|any",
     *     "rules": [ {"field": "<key>", "operator": "...", "value": "..."} ] }
     * Empty/no rules => always visible.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $conditions = [];

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

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): static
    {
        $this->key = $key;

        return $this;
    }

    public function getPlaceholder(): ?string
    {
        return $this->placeholder;
    }

    public function setPlaceholder(?string $placeholder): static
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function setRequired(bool $required): static
    {
        $this->required = $required;

        return $this;
    }

    /** @return string[] */
    public function getOptions(): array
    {
        return $this->options;
    }

    /** @param string[] $options */
    public function setOptions(array $options): static
    {
        $this->options = array_values($options);

        return $this;
    }

    public function hasOptions(): bool
    {
        return in_array($this->type, self::TYPES_WITH_OPTIONS, true);
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getConditions(): array
    {
        return $this->conditions;
    }

    /** @param array<string, mixed> $conditions */
    public function setConditions(array $conditions): static
    {
        $this->conditions = $conditions;

        return $this;
    }

    public function hasConditions(): bool
    {
        return !empty($this->conditions['rules'] ?? []);
    }
}
