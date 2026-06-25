<?php

namespace App\Controller\Admin;

use App\Readiness\ReadinessReport;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Deployment readiness panel — auto-diagnoses whether the install is correctly configured and
 * production-ready (green/yellow/red per item + fix instructions). Informational only: it NEVER
 * changes config. Checks the app can't verify with certainty (worker, webhook 401, real TLS) are
 * marked "provjeri ručno", never faked green. Lives in the EA shell under Sustav.
 */
#[Route('/admin/readiness', defaults: ['dashboardControllerFqcn' => 'App\Controller\Admin\DashboardController'])]
#[IsGranted('ROLE_ADMIN')]
class ReadinessController extends AbstractController
{
    public function __construct(
        private readonly ReadinessReport $report,
    ) {
    }

    #[Route('', name: 'admin_readiness', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/readiness.html.twig', $this->report->build());
    }
}
