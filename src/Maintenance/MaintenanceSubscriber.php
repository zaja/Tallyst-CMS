<?php

namespace App\Maintenance;

use App\Settings\SettingsManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

/**
 * Maintenance mode: when enabled, public visitors get a 503 maintenance page while the back-office keeps
 * working. Runs at priority 7 — just AFTER the security firewall (priority 8) so isGranted() can see the
 * logged-in user, and before the controller.
 *
 * Anti-lockout (double net): the `/admin` prefix is exempt (admin + login + reset + 2fa all live there, so
 * the admin can always get in to turn it off), AND a logged-in ROLE_ADMIN bypasses it entirely (live
 * preview). Always-public routes (webhooks, sitemap, robots) are exempt too — payment providers and
 * crawlers can't authenticate. Reading the toggle is fail-open: a settings/DB hiccup must never 503 the
 * whole site; only an explicit ON triggers maintenance.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
class MaintenanceSubscriber
{
    public function __construct(
        private readonly SettingsManager $settings,
        private readonly Security $security,
        private readonly Environment $twig,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        try {
            if (true !== $this->settings->get('maintenance_enabled')) {
                return;
            }
        } catch (\Throwable) {
            return; // fail-open — never take the site down because a settings read failed
        }

        $path = $event->getRequest()->getPathInfo();
        if (str_starts_with($path, '/admin')          // admin + login + reset-password + 2fa (no lockout)
            || str_starts_with($path, '/webhook')      // Stripe/PayPal — server-to-server
            || \in_array($path, ['/sitemap.xml', '/robots.txt'], true)) {
            return;
        }

        if ($this->security->isGranted('ROLE_ADMIN')) {
            return; // a logged-in admin previews the live site
        }

        $html = $this->twig->render('maintenance.html.twig', [
            'message' => (string) $this->settings->get('maintenance_message'),
        ]);
        $event->setResponse(new Response($html, Response::HTTP_SERVICE_UNAVAILABLE, ['Retry-After' => '3600']));
    }
}
