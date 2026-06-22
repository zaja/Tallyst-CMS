<?php

namespace App\Controller\Admin;

use App\Module\AdminModuleInterface;
use App\Module\ModuleRegistry;
use App\Module\ModuleStateManager;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly ModuleStateManager $moduleState,
    ) {
    }

    #[Route('/admin', name: 'admin')]
    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig');
    }

    #[Route('/admin/modules', name: 'admin_modules', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function modules(): Response
    {
        $rows = [];
        foreach ($this->modules->all() as $module) {
            $rows[] = [
                'name' => $module->getName(),
                'label' => $module->getLabel(),
                'version' => $module->getVersion(),
                'description' => $module->getDescription(),
                'enabled' => $this->moduleState->isEnabled($module->getName()),
            ];
        }

        return $this->render('admin/modules.html.twig', ['modules' => $rows]);
    }

    #[Route('/admin/modules/{name}/toggle', name: 'admin_modules_toggle', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function toggleModule(string $name, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('toggle-module-'.$name, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (null === $this->modules->get($name)) {
            throw $this->createNotFoundException(\sprintf('Module "%s" not found.', $name));
        }

        $this->moduleState->setEnabled($name, !$this->moduleState->isEnabled($name));
        $this->addFlash('success', \sprintf('Modul "%s" je ažuriran.', $name));

        return $this->redirectToRoute('admin_modules');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Tallyst CMS')
            ->setFaviconPath('favicon.ico');
    }

    public function configureAssets(): Assets
    {
        // Load the ADMIN entrypoint (Stimulus only, no front-end CSS) inside
        // EasyAdmin's single importmap so our controllers (formbuilder--*, etc.) boot
        // on admin pages without the front styles overriding the EA theme/dark mode.
        return Assets::new()->addAssetMapperEntry('admin');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Nadzorna ploča', 'fa fa-gauge');
        // Self-service 2FA — visible to EVERY logged-in user (no setPermission).
        yield MenuItem::linkToRoute('Sigurnost', 'fa fa-shield-halved', 'admin_security');

        // Content — visible to ROLE_EDITOR (and admins via hierarchy).
        yield MenuItem::section('Sadržaj');
        yield MenuItem::linkTo(PageCrudController::class, 'Stranice', 'fa fa-file-lines');
        yield MenuItem::linkTo(PostCrudController::class, 'Objave', 'fa fa-newspaper');
        yield MenuItem::linkTo(CategoryCrudController::class, 'Kategorije', 'fa fa-tags');

        // Everything below is admin-only. setPermission() here is COSMETIC (hides the link
        // from editors); the real gate is #[IsGranted('ROLE_ADMIN')] on each controller.
        yield MenuItem::section('Navigacija')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(MenuCrudController::class, 'Izbornici', 'fa fa-bars')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(MenuItemCrudController::class, 'Stavke izbornika', 'fa fa-list')->setPermission('ROLE_ADMIN');

        yield MenuItem::section('Izgled')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(ThemeCrudController::class, 'Teme', 'fa fa-palette')->setPermission('ROLE_ADMIN');

        yield MenuItem::section('Sustav')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkToRoute('Postavke', 'fa fa-gear', 'admin_settings')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkToRoute('Email predlošci', 'fa fa-envelope-open-text', 'admin_email_templates')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(UserCrudController::class, 'Korisnici', 'fa fa-users')->setPermission('ROLE_ADMIN');

        // Modules surface their own admin entries here, built dynamically from the registry.
        // The section header stays visible because Media's "Mediji" (content) is editor-
        // accessible; module-specific admin items carry their own ROLE_ADMIN permission.
        yield MenuItem::section('Moduli');
        yield MenuItem::linkToRoute('Instalirani moduli', 'fa fa-puzzle-piece', 'admin_modules')->setPermission('ROLE_ADMIN');
        foreach ($this->modules->all() as $module) {
            if (!$this->moduleState->isEnabled($module->getName())) {
                continue;
            }
            if ($module instanceof AdminModuleInterface) {
                yield from $module->getAdminMenuItems();
            }
        }
    }
}
