<?php

namespace App\Twig;

use App\Icon\IconRegistry;
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
 *
 * `icon_set_json('ui')` → the curated IconRegistry group as JSON `{name: {viewBox, body}}`,
 * projected into a Stimulus data-value on the editor element. This is how the SVG reaches the
 * JS side (the icon picker grid + the tallystIcon NodeView) — IconRegistry stays the SINGLE
 * source; JS gets a read-only projection, never a hand-maintained duplicate. Only the 'ui' group
 * is projected for now (the content picker); 'brands' is top-bar-only (step 4).
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
            new TwigFunction('icon_set_json', $this->iconSetJson(...)),
        ];
    }

    public function iconSetJson(string $group = 'ui'): string
    {
        $map = 'brands' === $group ? IconRegistry::BRANDS : IconRegistry::UI;

        $out = [];
        foreach ($map as $key => [$viewBox, $body]) {
            $out[$key] = ['viewBox' => $viewBox, 'body' => $body];
        }

        return json_encode($out, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: '{}';
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
