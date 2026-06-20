<?php

namespace App\Module;

/**
 * Collects every installed module (tagged "app.module") so the admin can list and
 * manage them. Modules self-register simply by implementing ModuleInterface.
 */
class ModuleRegistry
{
    /** @var array<string, ModuleInterface> */
    private array $modules = [];

    /**
     * @param iterable<ModuleInterface> $modules
     */
    public function __construct(iterable $modules = [])
    {
        foreach ($modules as $module) {
            $this->modules[$module->getName()] = $module;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->modules[$name]);
    }

    public function get(string $name): ?ModuleInterface
    {
        return $this->modules[$name] ?? null;
    }

    /** @return ModuleInterface[] */
    public function all(): array
    {
        return array_values($this->modules);
    }
}
