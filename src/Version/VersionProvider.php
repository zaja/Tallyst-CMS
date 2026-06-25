<?php

namespace App\Version;

use Composer\InstalledVersions;

/**
 * Reports installed component versions for the admin (support / troubleshooting).
 *
 * Phase 1 = CORE ONLY: {@see getCoreVersion()} reads the real installed version from Composer
 * metadata (no hardcoding, no manual upkeep). The FULL component map (core + modules + themes +
 * cross-compatibility) is BACKLOG (addon infrastructure) — a future getComponents() EXTENDS this
 * provider, it does not replace it.
 *
 * Source: Composer\InstalledVersions. On a Packagist / `create-project` install the package is
 * recorded under its name ("tallyst/cms") with the tagged version → "v1.1.0". On a bare dev git
 * clone there are no Packagist metadata for the package (the root is an unnamed "__root__" alias on
 * a branch), so resolution returns null and we fall back gracefully to "(dev)" — never throw.
 */
class VersionProvider
{
    public const CORE_PACKAGE = 'tallyst/cms';
    private const DEV_LABEL = '(dev)';

    /**
     * Display-ready core version for the admin: "v1.1.0" on a real (tagged) install, "(dev)" on a
     * dev git clone / branch checkout. Always a non-empty string — safe to print directly.
     */
    public function getCoreVersion(): string
    {
        $raw = $this->resolveCoreVersion();

        // null / empty / a branch ("dev-main") → not a real release → graceful fallback.
        if (null === $raw || '' === $raw || str_starts_with($raw, 'dev-')) {
            return self::DEV_LABEL;
        }

        // Normalise to a leading "v" for display ("1.1.0" → "v1.1.0"; "v1.1.0" stays).
        return str_starts_with($raw, 'v') ? $raw : 'v'.$raw;
    }

    /**
     * Raw pretty version from Composer metadata, or null when unavailable. Isolated + protected so
     * tests can stub it (InstalledVersions is static and can't be mocked).
     */
    protected function resolveCoreVersion(): ?string
    {
        // Installed under its name — a dependency, or a create-project root that kept its name.
        if (InstalledVersions::isInstalled(self::CORE_PACKAGE)) {
            $version = InstalledVersions::getPrettyVersion(self::CORE_PACKAGE);
            if (\is_string($version) && '' !== $version) {
                return $version;
            }
        }

        // Otherwise the root package may carry a clean pretty version (create-project / tag checkout).
        $root = InstalledVersions::getRootPackage();
        if (self::CORE_PACKAGE === ($root['name'] ?? null)) {
            $rootVersion = $root['pretty_version'] ?? null;

            return \is_string($rootVersion) && '' !== $rootVersion ? $rootVersion : null;
        }

        return null;
    }
}
