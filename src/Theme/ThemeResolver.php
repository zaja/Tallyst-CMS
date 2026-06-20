<?php

namespace App\Theme;

use App\Repository\ThemeRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Determines the active theme and the template lookup chain (child theme first,
 * then its parent(s)). Reads each theme's theme.yaml for metadata + parent link.
 */
class ThemeResolver
{
    private const MAX_PARENT_DEPTH = 5;

    private ?string $activeThemeName = null;

    public function __construct(
        private readonly ThemeRepository $themeRepository,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly string $defaultTheme = 'default',
    ) {
    }

    public function getActiveThemeName(): string
    {
        if (null !== $this->activeThemeName) {
            return $this->activeThemeName;
        }

        $name = $this->defaultTheme;
        try {
            $active = $this->themeRepository->findActive();
            if (null !== $active) {
                $name = $active->getName();
            }
        } catch (\Throwable) {
            // Theme table may not exist yet (before first migration) — use default.
        }

        return $this->activeThemeName = $name;
    }

    public function getThemesDir(): string
    {
        return $this->projectDir.'/themes';
    }

    public function getThemeDir(string $name): string
    {
        return $this->getThemesDir().'/'.$name;
    }

    /** @return array<string, mixed> */
    public function getThemeConfig(string $name): array
    {
        $file = $this->getThemeDir($name).'/theme.yaml';
        if (!is_file($file)) {
            return [];
        }

        try {
            return (array) (Yaml::parseFile($file) ?? []);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Theme names from the given (or active) theme up its parent chain, child-first
     * (e.g. ["child", "parent", "default"]). Cycle- and depth-guarded. This is the
     * single fallback order shared by template resolution and theme_asset().
     *
     * @return string[]
     */
    public function getThemeChain(?string $name = null): array
    {
        $name ??= $this->getActiveThemeName();

        $chain = [];
        $seen = [];
        $depth = 0;

        while (null !== $name && !isset($seen[$name]) && $depth < self::MAX_PARENT_DEPTH) {
            $seen[$name] = true;
            $chain[] = $name;

            $parent = $this->getThemeConfig($name)['parent'] ?? null;
            $name = (is_string($parent) && '' !== $parent) ? $parent : null;
            ++$depth;
        }

        return $chain;
    }

    /**
     * Template directories for the given (or active) theme, child-first up the
     * parent chain. Only existing directories are returned.
     *
     * @return string[]
     */
    public function getTemplatePathChain(?string $name = null): array
    {
        $paths = [];
        foreach ($this->getThemeChain($name) as $themeName) {
            $dir = $this->getThemeDir($themeName).'/templates';
            if (is_dir($dir)) {
                $paths[] = $dir;
            }
        }

        return $paths;
    }
}
