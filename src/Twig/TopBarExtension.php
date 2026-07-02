<?php

namespace App\Twig;

use App\Icon\IconRegistry;
use App\Settings\SettingsManager;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `social_links()` → the top bar's social icons ready to render: one `{key, url}` per brand icon
 * (IconRegistry::brandKeys()) whose `social_{brand}_url` setting is set AND scheme-safe. The layout
 * loops it and renders `icon(key)` inside an `<a href>`. Keeping the filter here (not in Twig) makes
 * it unit-testable and single-sources the brand list with the icon registry.
 */
class TopBarExtension extends AbstractExtension
{
    public function __construct(
        private readonly SettingsManager $settings,
        private readonly IconRegistry $icons,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('social_links', $this->socialLinks(...)),
        ];
    }

    /**
     * @return array<int, array{key: string, url: string}>
     */
    public function socialLinks(): array
    {
        $links = [];
        foreach ($this->icons->brandKeys() as $key) {
            $url = (string) $this->settings->get('social_'.$key.'_url');
            if (self::isSafeUrl($url)) {
                $links[] = ['key' => $key, 'url' => $url];
            }
        }

        return $links;
    }

    /**
     * A URL shows only when it's non-empty AND an http(s) link or a site-relative path. This is the
     * "loose" validation (relative paths allowed, unlike a strict UrlType) AND a guard against a
     * `javascript:`/`data:` scheme reaching the rendered `href`.
     */
    public static function isSafeUrl(string $url): bool
    {
        $url = trim($url);

        return '' !== $url
            && (str_starts_with($url, 'http://') || str_starts_with($url, 'https://') || str_starts_with($url, '/'));
    }
}
