<?php

namespace App\Entity;

use App\Repository\EmailTemplateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Admin OVERRIDE of one email type's subject/body/enabled. The set of known types and their
 * DEFAULT subject/body live in code (the EmailTypeRegistry); a row here exists only once an admin
 * has saved an override for that type. So `identifier` is the type key (e.g. "order_confirmation").
 */
#[ORM\Entity(repositoryClass: EmailTemplateRepository::class)]
#[ORM\Table(name: 'email_template')]
#[ORM\HasLifecycleCallbacks]
class EmailTemplate
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The email type key (unique). Matches an EmailType in the registry. */
    #[ORM\Column(length: 100, unique: true)]
    private string $identifier;

    #[ORM\Column(length: 255)]
    private string $subject = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $body = '';

    #[ORM\Column]
    private bool $enabled = true;

    public function __construct(string $identifier = '')
    {
        $this->identifier = $identifier;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): static
    {
        $this->identifier = $identifier;

        return $this;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }
}
