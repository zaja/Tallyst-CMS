<?php

namespace Tallyst\Media\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Branding Twig functions (the Media module is the consumer that knows Media):
 *  - render_branding() → theme-overridable header brand (logo or site-name fallback)
 *  - site_name(), branding_logo_url(filter) for direct use.
 *
 * Logic is in MediaRuntime (lazy) so this doesn't depend on Twig\Environment.
 */
class MediaExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('render_branding', [MediaRuntime::class, 'renderBranding'], ['is_safe' => ['html']]),
            new TwigFunction('site_name', [MediaRuntime::class, 'siteName']),
            new TwigFunction('branding_logo_url', [MediaRuntime::class, 'brandingLogoUrl']),
        ];
    }
}
