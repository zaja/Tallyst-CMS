<?php

namespace Tallyst\FormBuilder\Shortcode;

use App\Content\ShortcodeInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Tallyst\FormBuilder\Form\FormSchemaFactory;
use Tallyst\FormBuilder\Repository\FormDefinitionRepository;
use Twig\Environment;

/**
 * Handles [form id=N]: renders the published form as HTML so render_content can
 * splice it into page content. Core stays unaware of this tag — it self-registers
 * via the app.shortcode tag (ShortcodeInterface).
 */
class FormShortcode implements ShortcodeInterface
{
    public function __construct(
        private readonly FormDefinitionRepository $forms,
        private readonly FormSchemaFactory $schemas,
        private readonly Environment $twig,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getName(): string
    {
        return 'form';
    }

    public function render(array $attributes, ?string $content = null): string
    {
        $id = (int) ($attributes['id'] ?? 0);
        if ($id <= 0) {
            return '';
        }

        $form = $this->forms->findPublished($id);
        if (null === $form) {
            return \sprintf('<!-- Tallyst: form #%d not found or unpublished -->', $id);
        }

        [$errors, $old] = $this->pullFlash($id);

        return $this->twig->render('@FormBuilder/form/render.html.twig', [
            'form' => $form,
            'schema' => $this->schemas->client($form),
            'errors' => $errors,
            'old' => $old,
        ]);
    }

    /**
     * One-time read of validation errors + old input stashed by the submit
     * controller before its redirect. Only touches the session if one already
     * exists, so plain page views never force-start a session.
     *
     * @return array{0: array<string, string>, 1: array<string, mixed>}
     */
    private function pullFlash(int $id): array
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request || !$request->hasPreviousSession()) {
            return [[], []];
        }

        $bag = $request->getSession()->getFlashBag();

        /** @var array<string, string> $errors */
        $errors = $bag->get('fb_errors_'.$id)[0] ?? [];
        /** @var array<string, mixed> $old */
        $old = $bag->get('fb_old_'.$id)[0] ?? [];

        return [$errors, $old];
    }
}
