<?php

namespace App\Twig;

use App\Entity\MenuItem;
use App\Repository\MenuRepository;
use App\Theme\ThemeResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Twig\Extension\RuntimeExtensionInterface;

class ThemeRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly ThemeResolver $resolver,
        private readonly MenuRepository $menus,
        private readonly UrlGeneratorInterface $urls,
        private readonly Environment $twig,
        private readonly RequestStack $requestStack,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * Resolve a theme asset to a served URL, walking the active theme's parent chain.
     * Existence is checked at the SERVED location (public/themes/<name>/...), never the
     * source — so it never returns a URL to an unpublished asset. Cache-busted by mtime.
     */
    public function themeAsset(string $path): string
    {
        $path = ltrim($path, '/');

        foreach ($this->resolver->getThemeChain() as $themeName) {
            $served = $this->projectDir.'/public/themes/'.$themeName.'/'.$path;
            if (is_file($served)) {
                return '/themes/'.$themeName.'/'.$path.'?v='.filemtime($served);
            }
        }

        // Not published anywhere: point at the active theme so the resulting 404 is
        // visible (run app:theme:assets:install) rather than silently wrong.
        return '/themes/'.$this->resolver->getActiveThemeName().'/'.$path;
    }

    /**
     * Render a menu by its location through a theme-overridable template.
     */
    public function renderMenu(string $location): string
    {
        $menu = $this->menus->findOneByLocation($location);
        if (null === $menu) {
            return '';
        }

        $items = $this->buildItems($menu->getRootItems());
        if ([] === $items) {
            return '';
        }

        return $this->twig->render('menu.html.twig', [
            'items' => $items,
            'location' => $location,
        ]);
    }

    /**
     * @param iterable<MenuItem> $items
     *
     * @return array<int, array{label: string, url: string, active: bool, children: array}>
     */
    private function buildItems(iterable $items): array
    {
        $nodes = [];
        foreach ($items as $item) {
            $url = $this->resolveUrl($item);
            $nodes[] = [
                'label' => $item->getLabel(),
                'url' => $url,
                'active' => $this->isActive($url),
                'children' => $this->buildItems($item->getChildren()),
            ];
        }

        return $nodes;
    }

    private function resolveUrl(MenuItem $item): string
    {
        $page = $item->getPage();
        if (null !== $page) {
            return 'home' === $page->getSlug()
                ? $this->urls->generate('home')
                : $this->urls->generate('page_show', ['slug' => $page->getSlug()]);
        }

        return $item->getUrl() ?: '#';
    }

    /**
     * Home ("/") highlights on an EXACT match only — otherwise it would look active on
     * every page. Other items match exactly or as a path prefix (so "Blog" stays active
     * on /blog/{slug}).
     */
    private function isActive(string $url): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return false;
        }

        $path = $request->getPathInfo();
        if ('/' === $url) {
            return '/' === $path;
        }

        return $path === $url || str_starts_with($path, rtrim($url, '/').'/');
    }
}
