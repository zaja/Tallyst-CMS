<?php

namespace App\Icon;

use App\Content\EditorShortcodeConverterInterface;
use App\Content\ShortcodeAttributeParser;

/**
 * Converts `[icon name=X label="…"]` <-> a lightweight editor marker
 * `<span data-tallyst-icon data-name="X" [data-label="…"]></span>` at the Tiptap boundary
 * (modelled on ImageShortcodeHtmlConverter). The DB keeps the shortcode (front pipeline +
 * IconShortcode unchanged); the editor turns the marker into a real inline icon via the
 * tallystIcon node's NodeView (fed by the icon_set_json() projection).
 *
 * WYSIWYG split: the marker is DETERMINISTIC (name only, no SVG) so it round-trips cleanly and
 * is testable; the visible SVG is a NodeView concern (display-only), never serialized here.
 *
 * Disjoint pattern: only touches `[icon …]` (one way) and `<span data-tallyst-icon>` (the other),
 * leaving all other HTML and shortcodes ([image]/[form]) verbatim — so the converter chain stays
 * order-independent (a test locks this). Attribute grammar is shared with ContentRenderer via
 * ShortcodeAttributeParser so the two [icon] parsers can't drift.
 */
class IconEditorConverter implements EditorShortcodeConverterInterface
{
    /** Matches [icon …] (optionally self-closing). Mirrors ContentRenderer's tag shape. */
    private const SHORTCODE_RE = '/\[icon((?:\s+[\w\-]+(?:=(?:"[^"]*"|\'[^\']*\'|[^\s\]]+))?)*)\s*(?:\/\]|\])/s';

    /** Matches our empty marker span, regardless of attribute order. */
    private const SPAN_RE = '/<span\b[^>]*\bdata-tallyst-icon\b[^>]*>\s*<\/span>/i';

    public function __construct(
        private readonly ShortcodeAttributeParser $attributes = new ShortcodeAttributeParser(),
    ) {
    }

    /** DB content -> editor HTML: [icon …] becomes the marker span the node renders. */
    public function toEditorHtml(string $stored): string
    {
        if ('' === $stored) {
            return $stored;
        }

        return preg_replace_callback(self::SHORTCODE_RE, function (array $m): string {
            $attrs = $this->attributes->parse($m[1]);
            $name = isset($attrs['name']) ? (string) $attrs['name'] : '';
            if ('' === $name) {
                return $m[0]; // no name — not a usable icon; leave untouched (renders '' on front)
            }

            $label = isset($attrs['label']) ? (string) $attrs['label'] : '';
            $labelAttr = '' !== $label ? \sprintf(' data-label="%s"', $this->esc($label)) : '';

            return \sprintf('<span data-tallyst-icon="" data-name="%s"%s></span>', $this->esc($name), $labelAttr);
        }, $stored) ?? $stored;
    }

    /** Editor HTML -> DB content: our marker span becomes [icon …] again. */
    public function toStored(string $editorHtml): string
    {
        if ('' === $editorHtml) {
            return $editorHtml;
        }

        return preg_replace_callback(self::SPAN_RE, function (array $m): string {
            $tag = $m[0];
            $name = $this->attr($tag, 'data-name');
            if ('' === $name) {
                return ''; // marker without a name — drop it
            }

            $out = '[icon name='.$name;
            $label = html_entity_decode($this->attr($tag, 'data-label'), \ENT_QUOTES);
            if ('' !== $label) {
                $out .= ' label="'.str_replace('"', "'", $label).'"';
            }

            return $out.']';
        }, $editorHtml) ?? $editorHtml;
    }

    /** Read one HTML attribute value from a tag string (any quote style). */
    private function attr(string $tag, string $name): string
    {
        $re = '/\b'.preg_quote($name, '/').'\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i';
        if (!preg_match($re, $tag, $m)) {
            return '';
        }

        return ($m[2] ?? '') !== '' ? $m[2] : (($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? ''));
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES);
    }
}
