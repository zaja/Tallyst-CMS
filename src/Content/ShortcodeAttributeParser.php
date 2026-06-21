<?php

namespace App\Content;

/**
 * The ONE parser for shortcode attribute strings (e.g. ` id=5 size=medium alt="x"`).
 * Shared by ContentRenderer (front rendering) and the editor's
 * ImageShortcodeHtmlConverter so the `[image …]` attribute grammar can't drift between
 * the two. Bare attributes (no `=`) become boolean true.
 */
final class ShortcodeAttributeParser
{
    /**
     * @return array<string, string|bool>
     */
    public function parse(string $text): array
    {
        $attributes = [];
        if ('' === trim($text)) {
            return $attributes;
        }

        $re = '/([\w\-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s\]]+))|([\w\-]+)/';
        preg_match_all($re, $text, $matches, \PREG_SET_ORDER);

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
