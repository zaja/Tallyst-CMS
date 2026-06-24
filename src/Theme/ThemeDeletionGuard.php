<?php

namespace App\Theme;

use App\Entity\Theme;
use App\Repository\ThemeRepository;

/**
 * Server-side guard that stops the front-end from being bricked by deleting the theme it needs.
 * Pure decision logic (like AdminLockoutGuard): returns a human message when the delete must be
 * blocked, or null when it's allowed. V1 has NO theme-delete UI (themes are filesystem-managed via
 * FTP/git), so this is defense-in-depth against a programmatic/forced delete of a Theme row.
 */
class ThemeDeletionGuard
{
    public function __construct(private readonly ThemeRepository $themes)
    {
    }

    /** @return string|null block message, or null if the delete is allowed */
    public function blockDelete(Theme $target): ?string
    {
        // The default theme is the guaranteed fallback (git-tracked + ThemeResolver's safety net) — it
        // can NEVER be deleted, even when inactive and others exist. Defense-in-depth: V1 has no CMS
        // delete UI, but this still blocks a programmatic/forced delete.
        if ('default' === $target->getName()) {
            return 'Default tema je obavezna i ne može se obrisati.';
        }
        if ($target->isActive()) {
            return 'Ne možeš obrisati aktivnu temu.';
        }
        if ($this->themes->count([]) <= 1) {
            return 'Ne možeš obrisati jedinu temu.';
        }

        return null;
    }
}
