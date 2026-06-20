<?php

namespace App\Module;

use App\Repository\SettingRepository;

/**
 * Persists each module's enabled/disabled state in the Setting store (not in code),
 * keyed as `module.<name>.enabled`. Modules are enabled by default until disabled.
 */
class ModuleStateManager
{
    public function __construct(private readonly SettingRepository $settings)
    {
    }

    public function isEnabled(string $name): bool
    {
        return '0' !== $this->settings->get($this->key($name), '1');
    }

    public function setEnabled(string $name, bool $enabled): void
    {
        $this->settings->set($this->key($name), $enabled ? '1' : '0');
    }

    private function key(string $name): string
    {
        return 'module.'.$name.'.enabled';
    }
}
