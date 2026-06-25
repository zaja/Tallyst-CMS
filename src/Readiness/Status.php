<?php

namespace App\Readiness;

/**
 * Outcome of a readiness check. MANUAL is the deliberate honest status for checks the app
 * cannot verify with certainty (worker liveness, webhook reachability, real TLS) — shown with
 * instructions, NEVER faked as green. So a green badge can always be trusted.
 */
enum Status: string
{
    case OK = 'ok';
    case WARNING = 'warning';
    case PROBLEM = 'problem';
    case MANUAL = 'manual';

    /** EasyAdmin/Bootstrap badge class (matches the CRUD badges, dark-mode aware). */
    public function badgeClass(): string
    {
        return match ($this) {
            self::OK => 'badge-success',
            self::WARNING => 'badge-warning',
            self::PROBLEM => 'badge-danger',
            self::MANUAL => 'badge-secondary',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::OK => '✅',
            self::WARNING => '⚠',
            self::PROBLEM => '❌',
            self::MANUAL => '🔍',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::OK => 'OK',
            self::WARNING => 'Upozorenje',
            self::PROBLEM => 'Problem',
            self::MANUAL => 'Provjeri ručno',
        };
    }
}
