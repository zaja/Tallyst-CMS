<?php

namespace App\Entity;

use App\Repository\PageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Tallyst\Media\Entity\Media;

/**
 * A standalone content page. With an inline [form id=N] tag it becomes a product.
 */
#[ORM\Entity(repositoryClass: PageRepository::class)]
#[ORM\Table(name: 'page')]
#[ORM\HasLifecycleCallbacks]
class Page
{
    use TimestampableTrait;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 191)]
    private string $title;

    #[ORM\Column(length: 191, unique: true)]
    private string $slug;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $content = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_DRAFT;

    /** Theme template to render this page with; null = the theme default (page.html.twig). */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $template = null;

    #[ORM\Column(length: 191, nullable: true)]
    private ?string $metaTitle = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $metaDescription = null;

    #[ORM\Column]
    private int $position = 0;

    /** Featured image. core→Media FK is the ONE allowed core→module dependency (see CLAUDE.md). */
    #[ORM\ManyToOne(targetEntity: Media::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Media $featuredImage = null;

    /**
     * Per-page hero (opt-in, overlay). All optional; rendered only when heroEnabled and there's
     * an image or a title (see page.html.twig). heroImage mirrors the featuredImage FK pattern
     * (nullable, SET NULL so a deleted Media never 500s).
     */
    #[ORM\Column]
    private bool $heroEnabled = false;

    #[ORM\ManyToOne(targetEntity: Media::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Media $heroImage = null;

    #[ORM\Column(length: 191, nullable: true)]
    private ?string $heroTitle = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $heroText = null;

    #[ORM\Column(length: 191, nullable: true)]
    private ?string $heroCtaLabel = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $heroCtaUrl = null;

    public function __construct(string $title = '', string $slug = '')
    {
        $this->title = $title;
        $this->slug = $slug;
    }

    public function getFeaturedImage(): ?Media
    {
        return $this->featuredImage;
    }

    public function setFeaturedImage(?Media $featuredImage): static
    {
        $this->featuredImage = $featuredImage;

        return $this;
    }

    public function isHeroEnabled(): bool
    {
        return $this->heroEnabled;
    }

    public function setHeroEnabled(bool $heroEnabled): static
    {
        $this->heroEnabled = $heroEnabled;

        return $this;
    }

    public function getHeroImage(): ?Media
    {
        return $this->heroImage;
    }

    public function setHeroImage(?Media $heroImage): static
    {
        $this->heroImage = $heroImage;

        return $this;
    }

    public function getHeroTitle(): ?string
    {
        return $this->heroTitle;
    }

    public function setHeroTitle(?string $heroTitle): static
    {
        $this->heroTitle = $heroTitle;

        return $this;
    }

    public function getHeroText(): ?string
    {
        return $this->heroText;
    }

    public function setHeroText(?string $heroText): static
    {
        $this->heroText = $heroText;

        return $this;
    }

    public function getHeroCtaLabel(): ?string
    {
        return $this->heroCtaLabel;
    }

    public function setHeroCtaLabel(?string $heroCtaLabel): static
    {
        $this->heroCtaLabel = $heroCtaLabel;

        return $this;
    }

    public function getHeroCtaUrl(): ?string
    {
        return $this->heroCtaUrl;
    }

    public function setHeroCtaUrl(?string $heroCtaUrl): static
    {
        $this->heroCtaUrl = $heroCtaUrl;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function __toString(): string
    {
        return $this->title;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

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

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): static
    {
        $this->content = $content;

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

    public function getTemplate(): ?string
    {
        return $this->template;
    }

    public function setTemplate(?string $template): static
    {
        $this->template = $template;

        return $this;
    }

    public function getMetaTitle(): ?string
    {
        return $this->metaTitle;
    }

    public function setMetaTitle(?string $metaTitle): static
    {
        $this->metaTitle = $metaTitle;

        return $this;
    }

    public function getMetaDescription(): ?string
    {
        return $this->metaDescription;
    }

    public function setMetaDescription(?string $metaDescription): static
    {
        $this->metaDescription = $metaDescription;

        return $this;
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
}
