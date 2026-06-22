<?php

namespace App\Email;

/**
 * Aggregates every EmailTypeProviderInterface into a keyed map of EmailType definitions. The
 * admin list is driven from here (all types visible before any edit), and the renderer/sender
 * resolve defaults + required tags + canDisable from here.
 */
class EmailTypeRegistry
{
    /** @var array<string, EmailType>|null */
    private ?array $types = null;

    /**
     * @param iterable<EmailTypeProviderInterface> $providers
     */
    public function __construct(private readonly iterable $providers)
    {
    }

    /**
     * @return array<string, EmailType> keyed by type key
     */
    public function all(): array
    {
        if (null !== $this->types) {
            return $this->types;
        }

        $types = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->getEmailTypes() as $type) {
                $types[$type->key] = $type;
            }
        }

        return $this->types = $types;
    }

    public function get(string $key): ?EmailType
    {
        return $this->all()[$key] ?? null;
    }
}
