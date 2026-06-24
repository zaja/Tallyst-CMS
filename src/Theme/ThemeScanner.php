<?php

namespace App\Theme;

/**
 * Auto-detects themes from the filesystem (themes/<name>/), reading each theme.yaml for metadata. A
 * folder counts as a theme only if it has a theme.yaml; validity also needs a resolvable layout
 * (own or via the parent chain — so child themes that inherit the layout are valid). Never throws on
 * a malformed folder — it's reported as invalid, not crashed.
 *
 * The list is filesystem-driven (source of truth = the folders); ThemeResolver/Theme still own which
 * one is active.
 */
class ThemeScanner
{
    public function __construct(private readonly ThemeResolver $resolver)
    {
    }

    /**
     * @return list<array{name:string, label:string, author:?string, parent:?string, valid:bool,
     *                    parentMissing:bool, hasThumbnail:bool, isDefault:bool, active:bool}>
     */
    public function scan(): array
    {
        $dir = $this->resolver->getThemesDir();
        if (!is_dir($dir)) {
            return [];
        }

        $active = $this->resolver->getActiveThemeName();
        $present = $this->presentThemeNames($dir);

        $themes = [];
        foreach ($present as $name) {
            $config = $this->resolver->getThemeConfig($name);
            $parent = (isset($config['parent']) && \is_string($config['parent']) && '' !== $config['parent'])
                ? $config['parent'] : null;

            $themes[] = [
                'name' => $name,
                'label' => (isset($config['label']) && \is_string($config['label']) && '' !== $config['label'])
                    ? $config['label'] : $name,
                'author' => (isset($config['author']) && \is_string($config['author'])) ? $config['author'] : null,
                'parent' => $parent,
                'parentMissing' => null !== $parent && !\in_array($parent, $present, true),
                'valid' => $this->hasResolvableLayout($name),
                'hasThumbnail' => is_file($dir.'/'.$name.'/theme.png'),
                'isDefault' => 'default' === $name,
                'active' => $name === $active,
            ];
        }

        return $themes;
    }

    /** True if this exact theme is a detected, valid theme (used to gate activation/thumbnail). */
    public function isValidTheme(string $name): bool
    {
        if (1 !== preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
            return false;
        }
        $dir = $this->resolver->getThemeDir($name);

        return is_file($dir.'/theme.yaml') && $this->hasResolvableLayout($name);
    }

    /** @return list<string> folder names under themes/ that have a theme.yaml, sorted (default first). */
    private function presentThemeNames(string $dir): array
    {
        $names = [];
        foreach ((scandir($dir) ?: []) as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            if (is_dir($dir.'/'.$entry) && is_file($dir.'/'.$entry.'/theme.yaml')) {
                $names[] = $entry;
            }
        }
        sort($names);
        usort($names, static fn (string $a, string $b): int => ('default' === $a ? -1 : ('default' === $b ? 1 : 0)));

        return $names;
    }

    /** A layout.html.twig exists somewhere in the theme's chain (own templates or a parent's). */
    private function hasResolvableLayout(string $name): bool
    {
        foreach ($this->resolver->getTemplatePathChain($name) as $templateDir) {
            if (is_file($templateDir.'/layout.html.twig')) {
                return true;
            }
        }

        return false;
    }
}
