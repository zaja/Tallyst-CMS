<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Front-end theme Twig functions:
 *  - theme_asset('css/x.css') → URL to the active theme's published static asset
 *    (parent-chain fallback + cache-bust), served outside AssetMapper.
 *  - render_menu('main') → renders a Menu entity through a theme-overridable template.
 *  - icon('search', {label: 'GitHub'}) → inline SVG from the curated Core icon set
 *    (currentColor + 1em; theme search/UI/social icons). Same layer as the [icon] shortcode.
 *
 * The work lives in ThemeRuntime (a lazy runtime) so this extension doesn't depend
 * on Twig\Environment itself (which would be circular).
 */
class ThemeExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('theme_asset', [ThemeRuntime::class, 'themeAsset']),
            new TwigFunction('render_menu', [ThemeRuntime::class, 'renderMenu'], ['is_safe' => ['html']]),
            // is_safe html is sound: IconRenderer emits only allowlisted registry SVG, never input.
            new TwigFunction('icon', [ThemeRuntime::class, 'icon'], ['is_safe' => ['html']]),
        ];
    }
}
