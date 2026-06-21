<?php

namespace Tallyst\FormBuilder\Service;

use App\Content\EditorShortcodeConverterInterface;
use App\Content\ShortcodeAttributeParser;
use Tallyst\FormBuilder\Repository\FormDefinitionRepository;

/**
 * Converts `[form id=N]` <-> a visual editor card at the Tiptap form boundary, so a form
 * embed shows as a labelled block in the editor while the DB keeps the shortcode (front
 * pipeline + FormShortcode unchanged). One implementor of the Core
 * EditorShortcodeConverterInterface — FormBuilder plugs in here without the editor
 * depending on it.
 *
 * Touches ONLY [form …] / div[data-tallyst-form] and leaves everything else verbatim, so
 * it composes order-independently with the [image] converter. Shares the shortcode
 * attribute grammar via ShortcodeAttributeParser; null-safe label ("Forma #N") for a
 * deleted/unknown form.
 */
class FormShortcodeHtmlConverter implements EditorShortcodeConverterInterface
{
    private const SHORTCODE_RE = '/\[form((?:\s+[\w\-]+(?:=(?:"[^"]*"|\'[^\']*\'|[^\s\]]+))?)*)\s*(?:\/\]|\])/s';

    private const EMBED_RE = '/<div\b[^>]*\bdata-tallyst-form\b[^>]*>.*?<\/div>/is';

    public function __construct(
        private readonly FormDefinitionRepository $forms,
        private readonly ShortcodeAttributeParser $attributes = new ShortcodeAttributeParser(),
    ) {
    }

    public function toEditorHtml(string $stored): string
    {
        if ('' === $stored) {
            return $stored;
        }

        return preg_replace_callback(self::SHORTCODE_RE, function (array $m): string {
            $attrs = $this->attributes->parse($m[1]);
            $id = (int) ($attrs['id'] ?? 0);
            if ($id <= 0) {
                return $m[0];
            }

            $form = $this->forms->find($id);
            $label = null !== $form ? $form->getName() : 'Forma #'.$id;

            return \sprintf(
                '<div data-tallyst-form="" data-id="%d" data-label="%s">%s</div>',
                $id,
                $this->esc($label),
                $this->esc('Forma: '.$label),
            );
        }, $stored) ?? $stored;
    }

    public function toStored(string $editorHtml): string
    {
        if ('' === $editorHtml) {
            return $editorHtml;
        }

        return preg_replace_callback(self::EMBED_RE, function (array $m): string {
            $id = (int) $this->attr($m[0], 'data-id');

            return $id > 0 ? '[form id='.$id.']' : '';
        }, $editorHtml) ?? $editorHtml;
    }

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
