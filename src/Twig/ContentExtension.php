<?php

namespace App\Twig;

use App\Content\ContentRenderer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Exposes `{{ page.content|render_content }}` to templates, expanding replacement
 * tags through the ShortcodeRegistry. Output is marked safe HTML.
 */
class ContentExtension extends AbstractExtension
{
    public function __construct(private readonly ContentRenderer $renderer)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('render_content', $this->renderer->render(...), ['is_safe' => ['html']]),
        ];
    }
}
