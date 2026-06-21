<?php

namespace App\Tests\Content;

use App\Content\EditorContentConverter;
use PHPUnit\Framework\TestCase;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Repository\FormDefinitionRepository;
use Tallyst\FormBuilder\Service\FormShortcodeHtmlConverter;
use Tallyst\Media\Entity\Media;
use Tallyst\Media\Repository\MediaRepository;
use Tallyst\Media\Service\ImageShortcodeHtmlConverter;
use Tallyst\Media\Service\MediaImageHelper;

/**
 * Locks Decision 1: the editor converter chain is ORDER-INDEPENDENT. Each converter
 * touches only its own disjoint pattern ([image]/img vs [form]/div), so registration
 * order must not change the aggregate result — in either direction.
 */
class EditorContentConverterTest extends TestCase
{
    private function imageConverter(): ImageShortcodeHtmlConverter
    {
        $media = new Media();
        $media->setImageName('photo.jpg');
        $repo = $this->createStub(MediaRepository::class);
        $repo->method('find')->willReturn($media);

        return new ImageShortcodeHtmlConverter($repo, new MediaImageHelper());
    }

    private function formConverter(): FormShortcodeHtmlConverter
    {
        $form = $this->createStub(FormDefinition::class);
        $form->method('getName')->willReturn('Kontakt');
        $repo = $this->createStub(FormDefinitionRepository::class);
        $repo->method('find')->willReturn($form);

        return new FormShortcodeHtmlConverter($repo);
    }

    public function testToEditorHtmlIsOrderIndependent(): void
    {
        $image = $this->imageConverter();
        $form = $this->formConverter();
        $stored = '<p>Prije</p>[image id=5]<p>sredina</p>[form id=2]<p>poslije</p>';

        $ab = (new EditorContentConverter([$image, $form]))->toEditorHtml($stored);
        $ba = (new EditorContentConverter([$form, $image]))->toEditorHtml($stored);

        self::assertSame($ab, $ba);
        self::assertStringContainsString('data-tallyst-image', $ab);
        self::assertStringContainsString('data-tallyst-form', $ab);
    }

    public function testToStoredIsOrderIndependent(): void
    {
        $image = $this->imageConverter();
        $form = $this->formConverter();
        $editor = '<p>Prije</p><img data-tallyst-image data-id="5" data-size="medium" src="/x.jpg">'
            .'<div data-tallyst-form data-id="2" data-label="Kontakt">📋 Forma: Kontakt</div>';

        $ab = (new EditorContentConverter([$image, $form]))->toStored($editor);
        $ba = (new EditorContentConverter([$form, $image]))->toStored($editor);

        self::assertSame($ab, $ba);
        self::assertStringContainsString('[image id=5]', $ab);
        self::assertStringContainsString('[form id=2]', $ab);
    }

    /**
     * Prolaz C: columns are a PURE HTML node — no converter touches the wrapper. But the
     * converters run over the WHOLE HTML, so [image]/[form] embeds nested INSIDE a column
     * must still convert in BOTH directions while the column structure passes through
     * untouched. This is the PHP load->save round-trip for a page laid out in columns.
     */
    public function testColumnsWrapperPassesThroughWhileNestedEmbedsConvert(): void
    {
        $converter = new EditorContentConverter([$this->imageConverter(), $this->formConverter()]);

        $stored = '<div class="tallyst-columns" data-columns="2">'
            .'<div class="tallyst-column">[image id=5]</div>'
            .'<div class="tallyst-column">[form id=2]</div></div>';

        // load: shortcodes -> editor embeds, columns wrapper intact.
        $editor = $converter->toEditorHtml($stored);
        self::assertStringContainsString('class="tallyst-columns"', $editor);
        self::assertStringContainsString('data-columns="2"', $editor);
        self::assertSame(2, substr_count($editor, 'class="tallyst-column"'));
        self::assertStringContainsString('data-tallyst-image', $editor);
        self::assertStringContainsString('data-tallyst-form', $editor);

        // save: embeds -> shortcodes, columns wrapper still intact (full round-trip).
        $back = $converter->toStored($editor);
        self::assertStringContainsString('class="tallyst-columns"', $back);
        self::assertSame(2, substr_count($back, 'class="tallyst-column"'));
        self::assertStringContainsString('[image id=5]', $back);
        self::assertStringContainsString('[form id=2]', $back);
        self::assertStringNotContainsString('data-tallyst-', $back);
    }
}
