<?php

namespace Tallyst\Media\Service;

use App\Content\EditorShortcodeConverterInterface;
use App\Content\ShortcodeAttributeParser;
use Tallyst\Media\Repository\MediaRepository;

/**
 * Converts the `[image id=N size=… align=… alt="…"]` shortcode <-> a visual editor
 * <img> at the Tiptap form boundary. The DB keeps the shortcode (front pipeline +
 * ImageShortcode unchanged); the editor sees a real <img> so the image is WYSIWYG.
 *
 * Only this image marker is touched — all other HTML and other shortcodes (e.g.
 * [form id=N]) pass through verbatim. The shortcode attribute grammar is shared with
 * ContentRenderer via ShortcodeAttributeParser so the two can't drift; a coupling test
 * additionally proves a converter-produced [image …] renders identically to a hand-made
 * one through the real ImageShortcode.
 */
class ImageShortcodeHtmlConverter implements EditorShortcodeConverterInterface
{
    /** Matches [image …] (optionally self-closing). Mirrors ContentRenderer's tag shape. */
    private const SHORTCODE_RE = '/\[image((?:\s+[\w\-]+(?:=(?:"[^"]*"|\'[^\']*\'|[^\s\]]+))?)*)\s*(?:\/\]|\])/s';

    /** Matches an editor <img> carrying our marker, regardless of attribute order. */
    private const IMG_RE = '/<img\b[^>]*\bdata-tallyst-image\b[^>]*>/i';

    public function __construct(
        private readonly MediaRepository $media,
        private readonly MediaImageHelper $images,
        private readonly ShortcodeAttributeParser $attributes = new ShortcodeAttributeParser(),
    ) {
    }

    /** DB content -> editor HTML: [image …] becomes a real <img> the editor can show. */
    public function toEditorHtml(?string $stored): string
    {
        if (null === $stored || '' === $stored) {
            return (string) $stored;
        }

        return preg_replace_callback(self::SHORTCODE_RE, function (array $m): string {
            $attrs = $this->attributes->parse($m[1]);
            $id = (int) ($attrs['id'] ?? 0);
            if ($id <= 0) {
                return $m[0]; // not a real image tag — leave untouched
            }

            $media = $this->media->find($id);
            $size = isset($attrs['size']) ? (string) $attrs['size'] : 'medium';
            $align = isset($attrs['align']) ? (string) $attrs['align'] : '';
            $alt = isset($attrs['alt']) ? (string) $attrs['alt'] : (null !== $media ? ($media->getAlt() ?? '') : '');
            $width = isset($attrs['width']) ? (string) $attrs['width'] : '';
            // null-safe: a deleted Media yields an empty src but keeps data-id, so the
            // shortcode is preserved on save (and the front stays null-safe too).
            $src = null !== $media ? ($this->images->url($media->getImageName(), $size) ?? '') : '';

            return \sprintf(
                '<img data-tallyst-image="" data-id="%d" data-size="%s" data-align="%s" data-width="%s" src="%s" alt="%s">',
                $id,
                $this->esc($size),
                $this->esc($align),
                $this->esc($width),
                $this->esc($src),
                $this->esc($alt),
            );
        }, $stored) ?? $stored;
    }

    /** Editor HTML -> DB content: our <img> becomes [image …] again. */
    public function toStored(?string $html): string
    {
        if (null === $html || '' === $html) {
            return (string) $html;
        }

        return preg_replace_callback(self::IMG_RE, function (array $m): string {
            $tag = $m[0];
            $id = (int) $this->attr($tag, 'data-id');
            if ($id <= 0) {
                return ''; // marker without a usable id — drop it
            }

            return $this->buildShortcode(
                $id,
                $this->attr($tag, 'data-size'),
                $this->attr($tag, 'data-align'),
                $this->attr($tag, 'alt'),
                $this->attr($tag, 'data-width'),
            );
        }, $html) ?? $html;
    }

    private function buildShortcode(int $id, string $size, string $align, string $alt, string $width = ''): string
    {
        $out = '[image id='.$id;
        // 'medium' is the shortcode default — omit it to keep stored content clean.
        if ('' !== $size && 'medium' !== $size) {
            $out .= ' size='.$size;
        }
        if ('' !== $align) {
            $out .= ' align='.$align;
        }
        // Only 'full' is meaningful — 'normal'/empty is the default, omitted to keep content clean.
        if ('full' === $width) {
            $out .= ' width=full';
        }
        $alt = html_entity_decode($alt, \ENT_QUOTES);
        if ('' !== $alt) {
            // shortcode alt is double-quoted; collapse any stray double quotes.
            $out .= ' alt="'.str_replace('"', "'", $alt).'"';
        }

        return $out.']';
    }

    /** Read one HTML attribute value from a tag string (any quote style), decoded later. */
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
