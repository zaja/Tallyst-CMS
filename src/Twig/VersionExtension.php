<?php

namespace App\Twig;

use App\Version\VersionProvider;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the version info to templates: `tallyst_version()` returns the {@see VersionProvider} so a
 * template reads `tallyst_version().coreVersion` today and (once the addon version system lands) the
 * future component map off the SAME object — no template churn when the provider grows.
 */
class VersionExtension extends AbstractExtension
{
    public function __construct(private readonly VersionProvider $versions)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('tallyst_version', $this->version(...)),
        ];
    }

    public function version(): VersionProvider
    {
        return $this->versions;
    }
}
