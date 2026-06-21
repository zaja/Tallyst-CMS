<?php

namespace App\Twig;

use App\Module\ModuleRegistry;
use App\Module\ModuleStateManager;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `enabled_modules()` → space-separated list of currently-enabled module names. The
 * editor toolbar uses it to gate module-contributed insert buttons (e.g. "Ubaci formu"):
 * a disabled module's editor node stays registered (so existing embeds round-trip safely)
 * but its authoring button is hidden. Generic — the editor passes this list through; it
 * never names a specific module.
 */
class EditorExtension extends AbstractExtension
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly ModuleStateManager $state,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('enabled_modules', $this->enabledModules(...)),
        ];
    }

    public function enabledModules(): string
    {
        $names = [];
        foreach ($this->modules->all() as $module) {
            if ($this->state->isEnabled($module->getName())) {
                $names[] = $module->getName();
            }
        }

        return implode(' ', $names);
    }
}
