<?php

namespace App\Tests\FormBuilder;

use App\Content\ContentRenderer;
use App\Content\ShortcodeInterface;
use App\Content\ShortcodeRegistry;
use PHPUnit\Framework\TestCase;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Repository\FormDefinitionRepository;
use Tallyst\FormBuilder\Service\FormShortcodeHtmlConverter;

/**
 * Locks the [form id=N] <-> editor node conversion and proves the [form] grammar the
 * converter emits is parsed identically by the real ContentRenderer (so editor + front
 * can't drift).
 */
class FormShortcodeHtmlConverterTest extends TestCase
{
    private function converter(?FormDefinition $found): FormShortcodeHtmlConverter
    {
        $repo = $this->createStub(FormDefinitionRepository::class);
        $repo->method('find')->willReturn($found);

        return new FormShortcodeHtmlConverter($repo);
    }

    private function form(string $name): FormDefinition
    {
        $form = $this->createStub(FormDefinition::class);
        $form->method('getName')->willReturn($name);

        return $form;
    }

    public function testForwardBuildsCardWithLabel(): void
    {
        $html = $this->converter($this->form('Kontakt'))->toEditorHtml('[form id=2]');

        self::assertStringContainsString('data-tallyst-form', $html);
        self::assertStringContainsString('data-id="2"', $html);
        self::assertStringContainsString('data-label="Kontakt"', $html);
        self::assertStringContainsString('Forma: Kontakt', $html);
    }

    public function testForwardNullSafeLabelForMissingForm(): void
    {
        $html = $this->converter(null)->toEditorHtml('[form id=9]');
        self::assertStringContainsString('data-id="9"', $html);
        self::assertStringContainsString('Forma #9', $html);
    }

    public function testForwardLeavesOtherContentUntouched(): void
    {
        $in = '<p>Tekst</p>[image id=5]<h2>Naslov</h2>';
        self::assertSame($in, $this->converter($this->form('X'))->toEditorHtml($in));
    }

    public function testReverseRebuildsShortcode(): void
    {
        $div = '<div data-tallyst-form="" data-id="2" data-label="Kontakt">📋 Forma: Kontakt</div>';
        self::assertSame('[form id=2]', $this->converter(null)->toStored($div));
    }

    public function testReverseLeavesImageAndTextUntouched(): void
    {
        $in = '<p>Tekst</p><img data-tallyst-image data-id="5" src="/x.jpg">';
        self::assertSame($in, $this->converter(null)->toStored($in));
    }

    public function testRoundTrip(): void
    {
        $c = $this->converter($this->form('Kontakt'));
        self::assertSame('[form id=2]', $c->toStored($c->toEditorHtml('[form id=2]')));
    }

    /**
     * COUPLING: the [form id=N] the converter emits must be parsed by the real
     * ContentRenderer to attributes id=N (same grammar the front pipeline uses).
     */
    public function testConverterOutputParsesOnFrontGrammar(): void
    {
        $shortcode = $this->converter(null)->toStored(
            '<div data-tallyst-form data-id="2" data-label="Kontakt">📋 Forma: Kontakt</div>',
        );

        $recorder = new class implements ShortcodeInterface {
            /** @var array<string, string|bool> */
            public array $seen = [];

            public function getName(): string
            {
                return 'form';
            }

            public function render(array $attributes, ?string $content = null): string
            {
                $this->seen = $attributes;

                return 'RENDERED';
            }
        };

        $renderer = new ContentRenderer(new ShortcodeRegistry([$recorder]));
        $out = $renderer->render($shortcode);

        self::assertSame('RENDERED', $out);
        self::assertSame('2', $recorder->seen['id'] ?? null);
    }
}
