<?php

namespace App\Entity;

use App\Repository\ThemeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Registry row for an installed front-end theme. The folder under themes/<name>
 * holds the actual templates/assets; this row tracks installation + which is active.
 */
#[ORM\Entity(repositoryClass: ThemeRepository::class)]
#[ORM\Table(name: 'theme')]
#[ORM\HasLifecycleCallbacks]
class Theme
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Machine name = folder name under themes/ (e.g. "default"). */
    #[ORM\Column(length: 100, unique: true)]
    private string $name;

    #[ORM\Column(length: 191, nullable: true)]
    private ?string $label = null;

    #[ORM\Column]
    private bool $active = false;

    public function __construct(string $name = '', ?string $label = null)
    {
        $this->name = $name;
        $this->label = $label;
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }
}
