<?php

namespace App\Theme;

use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Yaml\Yaml;

/**
 * Registers each theme's own translation catalogs with the translator, so a theme can carry its
 * UI strings in `themes/<name>/translations/<domain>.<locale>.yaml` (theme Twig uses `|trans`,
 * the `messages` domain). Themes are NOT Symfony bundles, so the translator wouldn't scan them
 * otherwise — this mirrors the template/asset model where a theme ships its own resources.
 *
 * Ordering: a theme's PARENT is registered BEFORE the theme itself, so a child theme overrides
 * the parent's keys (the translator merges resources in add-order, last wins) — the same
 * inheritance as templates. Theme keys are namespaced (`theme.*`) and a site renders one active
 * theme, so cross-theme key collisions are a non-issue in practice.
 */
class ThemeTranslationPass implements CompilerPassInterface
{
    public function __construct(private readonly string $projectDir)
    {
    }

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('translator.default')) {
            return;
        }

        $themesDir = $this->projectDir.'/themes';
        if (!is_dir($themesDir)) {
            return;
        }

        // theme name => parent name (or null), for parent-first ordering.
        $parents = [];
        foreach (glob($themesDir.'/*/theme.yaml') ?: [] as $yaml) {
            $name = basename(\dirname($yaml));
            $cfg = Yaml::parseFile($yaml);
            $parent = (\is_array($cfg) && isset($cfg['parent']) && \is_string($cfg['parent']) && '' !== $cfg['parent']) ? $cfg['parent'] : null;
            $parents[$name] = $parent;
        }

        $translator = $container->getDefinition('translator.default');

        foreach ($this->parentsFirst($parents) as $name) {
            $dir = $themesDir.'/'.$name.'/translations';
            if (!is_dir($dir)) {
                continue;
            }

            // Track the directory so the container rebuilds when catalogs change.
            $container->addResource(new DirectoryResource($dir, '/\.ya?ml$/'));

            foreach (glob($dir.'/*.*.yaml') ?: [] as $file) {
                $base = basename($file, '.yaml'); // e.g. "messages.hr"
                $dot = strrpos($base, '.');
                if (false === $dot) {
                    continue;
                }
                $domain = substr($base, 0, $dot);
                $locale = substr($base, $dot + 1);
                $translator->addMethodCall('addResource', ['yaml', $file, $locale, $domain]);
            }
        }
    }

    /**
     * Order theme names so each theme's parent (when the parent is also a local theme) is listed
     * before it — child catalogs then load after the parent's and override them.
     *
     * @param array<string, string|null> $parents
     *
     * @return list<string>
     */
    private function parentsFirst(array $parents): array
    {
        $ordered = [];
        $visiting = [];

        $visit = function (string $name) use (&$visit, &$ordered, &$visiting, $parents): void {
            if (\in_array($name, $ordered, true) || isset($visiting[$name])) {
                return; // already placed, or a parent cycle — bail safely
            }
            $visiting[$name] = true;
            $parent = $parents[$name] ?? null;
            if (null !== $parent && \array_key_exists($parent, $parents)) {
                $visit($parent);
            }
            unset($visiting[$name]);
            $ordered[] = $name;
        };

        foreach (array_keys($parents) as $name) {
            $visit($name);
        }

        return $ordered;
    }
}
