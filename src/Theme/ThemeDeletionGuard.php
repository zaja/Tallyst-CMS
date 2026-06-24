<?php

namespace App\Theme;

use App\Entity\Theme;
use App\Repository\ThemeRepository;

/**
 * Server-side guard that stops the front-end from being bricked by deleting the theme it needs.
 * Pure decision logic (like AdminLockoutGuard): returns a human message when the delete must be
 * blocked, or null when it's allowed; the caller (ThemeCrudController) turns a message into a
 * flash + abort (never the deletion).
 */
class ThemeDeletionGuard
{
    public function __construct(private readonly ThemeRepository $themes)
    {
    }

    /** @return string|null block message, or null if the delete is allowed */
    public function blockDelete(Theme $target): ?string
    {
        if ($target->isActive()) {
            return 'Ne možeš obrisati aktivnu temu.';
        }
        if ($this->themes->count([]) <= 1) {
            return 'Ne možeš obrisati jedinu temu.';
        }

        return null;
    }
}
