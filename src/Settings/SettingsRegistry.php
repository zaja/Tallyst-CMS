<?php

namespace App\Settings;

/**
 * Aggregates every tagged SettingsSectionProviderInterface into the ordered list of
 * sections + a flat key->definition lookup. Mirrors the ShortcodeRegistry / ModuleRegistry
 * IoC pattern: Core and modules contribute sections; nothing here knows the concrete
 * providers.
 */
class SettingsRegistry
{
    /** @var SettingsSection[]|null */
    private ?array $sections = null;

    /** @var array<string, SettingDefinition>|null */
    private ?array $definitions = null;

    /**
     * @param iterable<SettingsSectionProviderInterface> $providers
     */
    public function __construct(private readonly iterable $providers = [])
    {
    }

    /**
     * @return SettingsSection[]
     */
    public function getSections(): array
    {
        if (null === $this->sections) {
            $this->sections = [];
            foreach ($this->providers as $provider) {
                foreach ($provider->getSettingsSections() as $section) {
                    $this->sections[] = $section;
                }
            }
        }

        return $this->sections;
    }

    public function getDefinition(string $key): ?SettingDefinition
    {
        return $this->allDefinitions()[$key] ?? null;
    }

    /**
     * @return array<string, SettingDefinition>
     */
    public function allDefinitions(): array
    {
        if (null === $this->definitions) {
            $this->definitions = [];
            foreach ($this->getSections() as $section) {
                foreach ($section->definitions as $def) {
                    $this->definitions[$def->key] = $def;
                }
            }
        }

        return $this->definitions;
    }
}
