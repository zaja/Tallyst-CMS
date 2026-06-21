<?php

namespace App\Content;

/**
 * Aggregates every tagged EditorShortcodeConverterInterface and applies them in a chain
 * at the editor boundary. Because each converter touches only its own disjoint pattern,
 * the chain is order-independent (a test locks this). This is the single service the
 * editor's TiptapType depends on — modules plug in converters without the editor knowing
 * them.
 */
class EditorContentConverter
{
    /** @var iterable<EditorShortcodeConverterInterface> */
    private iterable $converters;

    /**
     * @param iterable<EditorShortcodeConverterInterface> $converters
     */
    public function __construct(iterable $converters = [])
    {
        $this->converters = $converters;
    }

    public function toEditorHtml(?string $stored): string
    {
        $html = (string) $stored;
        foreach ($this->converters as $converter) {
            $html = $converter->toEditorHtml($html);
        }

        return $html;
    }

    public function toStored(?string $editorHtml): string
    {
        $stored = (string) $editorHtml;
        foreach ($this->converters as $converter) {
            $stored = $converter->toStored($stored);
        }

        return $stored;
    }
}
