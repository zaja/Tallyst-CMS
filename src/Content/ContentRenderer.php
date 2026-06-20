<?php

namespace App\Content;

/**
 * Runs the ShortcodeRegistry over raw stored content, replacing known tags with
 * their handler output. Unknown bracketed text is left untouched. Exposed to Twig
 * as the `render_content` filter.
 */
class ContentRenderer
{
    public function __construct(private readonly ShortcodeRegistry $registry)
    {
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

            $attributes = $this->parseAttributes($m[2] ?? '');
            $inner = ($m[3] ?? '') !== '' ? $m[3] : null;

            return $handler->render($attributes, $inner);
        }, $content);

        return $result ?? $content;
    }

    /**
     * Parses an attribute string like ` id=5 label="Buy now" featured` into a map.
     * Bare attributes (no `=`) become boolean true.
     *
     * @return array<string, string|bool>
     */
    private function parseAttributes(string $text): array
    {
        $attributes = [];
        if ('' === trim($text)) {
            return $attributes;
        }

        $re = '/([\w\-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s\]]+))|([\w\-]+)/';
        preg_match_all($re, $text, $matches, PREG_SET_ORDER);

        foreach ($matches as $m) {
            if (isset($m[1]) && '' !== $m[1]) {
                // key=value form: pick whichever quote/bare group captured.
                if (isset($m[2]) && '' !== $m[2]) {
                    $value = $m[2];
                } elseif (isset($m[3]) && '' !== $m[3]) {
                    $value = $m[3];
                } else {
                    $value = $m[4] ?? '';
                }
                $attributes[strtolower($m[1])] = $value;
            } elseif (isset($m[5]) && '' !== $m[5]) {
                $attributes[strtolower($m[5])] = true;
            }
        }

        return $attributes;
    }
}
