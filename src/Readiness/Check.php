<?php

namespace App\Readiness;

/**
 * One readiness check result. `fix` is the actionable instruction shown for non-OK statuses.
 */
final readonly class Check
{
    public function __construct(
        public string $group,
        public string $label,
        public Status $status,
        public string $detail,
        public ?string $fix = null,
    ) {
    }

    public static function ok(string $group, string $label, string $detail): self
    {
        return new self($group, $label, Status::OK, $detail);
    }

    public static function warning(string $group, string $label, string $detail, ?string $fix = null): self
    {
        return new self($group, $label, Status::WARNING, $detail, $fix);
    }

    public static function problem(string $group, string $label, string $detail, ?string $fix = null): self
    {
        return new self($group, $label, Status::PROBLEM, $detail, $fix);
    }

    public static function manual(string $group, string $label, string $detail, ?string $fix = null): self
    {
        return new self($group, $label, Status::MANUAL, $detail, $fix);
    }
}
