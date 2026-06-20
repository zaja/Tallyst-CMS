<?php

namespace App\Entity;

use App\Repository\MenuRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A named navigation menu bound to a location (e.g. "main", "footer").
 */
#[ORM\Entity(repositoryClass: MenuRepository::class)]
#[ORM\Table(name: 'menu')]
#[ORM\HasLifecycleCallbacks]
class Menu
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 191)]
    private string $name;

    /** Placement handle, unique (e.g. "main", "footer"). */
    #[ORM\Column(length: 100, unique: true)]
    private string $location;

    /** @var Collection<int, MenuItem> */
    #[ORM\OneToMany(targetEntity: MenuItem::class, mappedBy: 'menu', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $items;

    public function __construct(string $name = '', string $location = '')
    {
        $this->name = $name;
        $this->location = $location;
        $this->items = new ArrayCollection();
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

    public function getLocation(): string
    {
        return $this->location;
    }

    public function setLocation(string $location): static
    {
        $this->location = $location;

        return $this;
    }

    /** @return Collection<int, MenuItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    /** Top-level items only (no parent), already ordered by position. */
    public function getRootItems(): Collection
    {
        return $this->items->filter(static fn (MenuItem $item) => null === $item->getParent());
    }

    public function addItem(MenuItem $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setMenu($this);
        }

        return $this;
    }

    public function removeItem(MenuItem $item): static
    {
        $this->items->removeElement($item);

        return $this;
    }
}
