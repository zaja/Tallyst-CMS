<?php

namespace App\Email;

/**
 * Definition of one known email type (the code source of truth): its key, label, the {tags}
 * available to the admin (name => human description), which tags are REQUIRED in the body, whether
 * it may be disabled, and the DEFAULT subject + body. The DB (EmailTemplate) only overrides
 * subject/body/enabled per type; everything else lives here.
 */
final readonly class EmailType
{
    /**
     * @param array<string, string> $tags         tag name (without braces) => description
     * @param string[]              $requiredTags  tag names that MUST appear in the body
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $tags,
        public array $requiredTags,
        public bool $canDisable,
        public string $defaultSubject,
        public string $defaultBody,
    ) {
    }
}
