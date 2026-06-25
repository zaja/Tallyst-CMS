<?php

namespace Tallyst\FormBuilder\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Tallyst\FormBuilder\Service\WebhookReachabilityProbe;

/**
 * On-demand JSON endpoint for the readiness panel's webhook 401 self-test. NOT an EA-shell page
 * (returns JSON) → no dashboardControllerFqcn default. ROLE_ADMIN + CSRF. The probe is safe (an
 * unsigned body fails signature verification → 400, so no order logic runs).
 */
#[Route('/admin/forms/webhook-check')]
#[IsGranted('ROLE_ADMIN')]
class WebhookCheckController extends AbstractController
{
    public const CSRF_ID = 'webhook_check';

    public function __construct(
        private readonly WebhookReachabilityProbe $probe,
    ) {
    }

    #[Route('', name: 'form_builder_admin_webhook_check', methods: ['POST'])]
    public function check(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Neispravan CSRF token.'], 403);
        }

        return $this->json(['results' => $this->probe->probe()]);
    }
}
