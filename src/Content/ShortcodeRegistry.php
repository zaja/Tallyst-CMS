<?php

namespace App\Content;

/**
 * Holds the set of available shortcode handlers, keyed by tag name. Handlers are
 * injected via the "app.shortcode" tagged iterator (see config/services.yaml).
 */
class ShortcodeRegistry
{
    /** @var array<string, ShortcodeInterface> */
    private array $shortcodes = [];

    /**
     * @param iterable<ShortcodeInterface> $shortcodes
     */
    public function __construct(iterable $shortcodes = [])
    {
        foreach ($shortcodes as $shortcode) {
            $this->register($shortcode);
        }
    }

    public function register(ShortcodeInterface $shortcode): void
    {
        $this->shortcodes[$shortcode->getName()] = $shortcode;
    }

    public function has(string $name): bool
    {
        return isset($this->shortcodes[$name]);
    }

    public function get(string $name): ?ShortcodeInterface
    {
        return $this->shortcodes[$name] ?? null;
    }

    /** @return string[] */
    public function names(): array
    {
        return array_keys($this->shortcodes);
    }

    /** @return ShortcodeInterface[] */
    public function all(): array
    {
        return array_values($this->shortcodes);
    }
}
