<?php

namespace App\Controller\Admin;

use App\Console\ConsoleStepRunner;
use App\Repository\PageRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Admin UI over the `app:demo:seed` command: install (additive seed) or delete (--clear) the
 * clearly-demo content, so someone evaluating Tallyst can preview the front-end and then remove it.
 * A MECHANISM over the existing command (richer demo CONTENT is a separate, later effort).
 *
 * Runs the command as a fresh-kernel subprocess via ConsoleStepRunner (the demo logic lives in the
 * command, not a service). Delete targets only the demo's FIXED handles — but that set includes the
 * `home` page and the `main` menu the demo owns, so the UI says so honestly (no "untouched" overclaim).
 */
#[Route('/admin/demo', defaults: ['dashboardControllerFqcn' => 'App\Controller\Admin\DashboardController'])]
#[IsGranted('ROLE_ADMIN')]
class DemoController extends AbstractController
{
    // A clearly-demo page slug (NOT 'home', which app:install also creates) → "demo present" signal.
    private const DEMO_MARKER_SLUG = 'o-nama';

    public function __construct(
        private readonly ConsoleStepRunner $steps,
        private readonly PageRepository $pages,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'admin_demo', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/demo.html.twig', [
            'installed' => $this->isInstalled(),
        ]);
    }

    #[Route('/install', name: 'admin_demo_install', methods: ['POST'])]
    public function install(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('demo-install', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', $this->translator->trans('admin.demo.flash.csrf', [], 'admin'));

            return $this->redirectToRoute('admin_demo');
        }

        if ($this->runDemo(['app:demo:seed'])) {
            $this->addFlash('success', $this->translator->trans('admin.demo.flash.installed', [], 'admin'));
        } else {
            $this->addFlash('danger', $this->translator->trans('admin.demo.flash.failed', [], 'admin'));
        }

        return $this->redirectToRoute('admin_demo');
    }

    #[Route('/delete', name: 'admin_demo_delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('demo-delete', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', $this->translator->trans('admin.demo.flash.csrf', [], 'admin'));

            return $this->redirectToRoute('admin_demo');
        }

        if ($this->runDemo(['app:demo:seed', '--clear'])) {
            $this->addFlash('success', $this->translator->trans('admin.demo.flash.deleted', [], 'admin'));
        } else {
            $this->addFlash('danger', $this->translator->trans('admin.demo.flash.failed', [], 'admin'));
        }

        return $this->redirectToRoute('admin_demo');
    }

    #[Route('/unflag', name: 'admin_demo_unflag', methods: ['POST'])]
    public function unflag(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('demo-unflag', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', $this->translator->trans('admin.demo.flash.csrf', [], 'admin'));

            return $this->redirectToRoute('admin_demo');
        }

        // Make the demo content permanent (clear is_demo everywhere). One-way: the uninstaller can no
        // longer remove it — the admin accepts that trade-off (stated in the UI + the confirm dialog).
        if ($this->runDemo(['app:demo:seed', '--unflag'])) {
            $this->addFlash('success', $this->translator->trans('admin.demo.flash.unflagged', [], 'admin'));
        } else {
            $this->addFlash('danger', $this->translator->trans('admin.demo.flash.failed', [], 'admin'));
        }

        return $this->redirectToRoute('admin_demo');
    }

    private function isInstalled(): bool
    {
        return null !== $this->pages->findOneBy(['slug' => self::DEMO_MARKER_SLUG]);
    }

    /**
     * @param array<int, string> $args
     */
    private function runDemo(array $args): bool
    {
        // ConsoleStepRunner wants a SymfonyStyle; a buffered one captures (and discards) the command
        // output in the web context. childEnv() makes the child re-read .env.local (same DB).
        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());

        return $this->steps->run($io, $args, $this->steps->childEnv());
    }
}
