<?php

namespace App\Theme;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Prepends the active theme's template directories to Twig's loader on each main
 * request, so plain template names (page.html.twig, layout.html.twig, ...) resolve
 * from the active theme first, then its parent(s), then the app's own templates/.
 */
class ThemeTemplateSubscriber implements EventSubscriberInterface
{
    private bool $applied = false;

    public function __construct(
        private readonly Environment $twig,
        private readonly ThemeResolver $resolver,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After the router (32), before the controller runs.
        return [KernelEvents::REQUEST => ['onKernelRequest', 8]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $this->applied) {
            return;
        }

        // The admin (and Symfony internal routes) must never be touched by the
        // front-end theme resolver — they use their own templates.
        $path = $event->getRequest()->getPathInfo();
        if (str_starts_with($path, '/admin') || str_starts_with($path, '/_')) {
            return;
        }

        $loader = $this->twig->getLoader();
        if (!$loader instanceof FilesystemLoader) {
            return;
        }

        // Prepend parent-first so the active (child) theme ends up at the front.
        foreach (array_reverse($this->resolver->getTemplatePathChain()) as $path) {
            $loader->prependPath($path);
        }

        $this->applied = true;
    }
}
