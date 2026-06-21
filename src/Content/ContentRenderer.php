<?php

namespace App\Content;

/**
 * Runs the ShortcodeRegistry over raw stored content, replacing known tags with
 * their handler output. Unknown bracketed text is left untouched. Exposed to Twig
 * as the `render_content` filter.
 */
class ContentRenderer
{
    public function __construct(
        private readonly ShortcodeRegistry $registry,
        private readonly ShortcodeAttributeParser $attributes = new ShortcodeAttributeParser(),
    ) {
    }

    public function render(?string $content): string
    {
        if (null === $content || '' === $content) {
            return (string) $content;
        }

        $names = $this->registry->names();
        if ([] === $names || !str_contains($content, '[')) {
            return $content;
        }

        $tagPattern = implode('|', array_map(static fn (string $n): string => preg_quote($n, '/'), $names));

        // Matches: [tag attrs/]  or  [tag attrs]  or  [tag attrs]inner[/tag]
        $pattern = '/\[('.$tagPattern.')((?:\s+[\w\-]+(?:=(?:"[^"]*"|\'[^\']*\'|[^\s\]]+))?)*)\s*(?:\/\]|\](?:(.*?)\[\/\1\])?)/s';

        $result = preg_replace_callback($pattern, function (array $m): string {
            $handler = $this->registry->get($m[1]);
            if (null === $handler) {
                return $m[0];
            }

            $attributes = $this->attributes->parse($m[2] ?? '');
            $inner = ($m[3] ?? '') !== '' ? $m[3] : null;

            return $handler->render($attributes, $inner);
        }, $content);

        return $result ?? $content;
    }
}
