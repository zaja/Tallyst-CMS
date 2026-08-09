<?php

namespace App\Customer;

/**
 * A freshly issued login link, as the caller needs it: the two token halves in the clear, so the
 * mail can be built, plus the normalised address.
 *
 * ⚠ This object is the ONLY place the verifier ever exists in readable form, and it must never be
 * persisted or logged. What goes into the database is a hash of it.
 */
final readonly class LoginLinkResult
{
    public function __construct(
        public string $email,
        public string $selector,
        public string $verifier,
        public \DateTimeImmutable $expiresAt,
    ) {
    }

    /** The `selector.verifier` pair as it travels in the URL. */
    public function token(): string
    {
        return $this->selector.'.'.$this->verifier;
    }
}
