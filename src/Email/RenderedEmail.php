<?php

namespace App\Email;

/**
 * The output of EmailRenderer: a ready subject + wrapped HTML body (+ a plain-text fallback).
 */
final readonly class RenderedEmail
{
    public function __construct(
        public string $subject,
        public string $html,
        public string $text,
    ) {
    }
}
