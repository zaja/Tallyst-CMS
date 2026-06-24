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
                'core' => $module->isCore(),
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

        $module = $this->modules->get($name);
        if (null === $module) {
            throw $this->createNotFoundException(\sprintf('Module "%s" not found.', $name));
        }

        // Core modules can't be disabled — reject even a direct/forged request (UI hides the toggle too).
        if ($module->isCore()) {
            $this->addFlash('warning', \sprintf('Modul "%s" je dio jezgre i ne može se isključiti.', $module->getLabel()));

            return $this->redirectToRoute('admin_modules');
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
        // Collect enabled modules' admin items grouped by section key — the module declares its own
        // placement (Forme/Mediji → Sadržaj, Narudžbe → Prodaja), so Core never references module
        // controllers (dependency direction). Disabled modules are skipped (unchanged gate).
        $moduleItems = [];
        foreach ($this->modules->all() as $module) {
            if (!$this->moduleState->isEnabled($module->getName())) {
                continue;
            }
            if ($module instanceof AdminModuleInterface) {
                foreach ($module->getAdminMenuItems() as $sectionKey => $items) {
                    foreach ($items as $item) {
                        $moduleItems[$sectionKey][] = $item;
                    }
                }
            }
        }

        yield MenuItem::linkToDashboard('Nadzorna ploča', 'fa fa-gauge');

        // SADRŽAJ — core content + module content tools (Forme [admin], Mediji [editor]). Editor-visible.
        yield MenuItem::section('Sadržaj');
        yield MenuItem::linkTo(PageCrudController::class, 'Stranice', 'fa fa-file-lines');
        yield MenuItem::linkTo(PostCrudController::class, 'Objave', 'fa fa-newspaper');
        yield MenuItem::linkTo(CategoryCrudController::class, 'Kategorije', 'fa fa-tags');
        yield from $moduleItems[AdminModuleInterface::SECTION_CONTENT] ?? [];

        // PRODAJA — only when a module contributes sales items (FormBuilder Narudžbe). Admin-only,
        // and conditional so editors never see an empty section header.
        if (!empty($moduleItems[AdminModuleInterface::SECTION_SALES])) {
            yield MenuItem::section('Prodaja')->setPermission('ROLE_ADMIN');
            yield from $moduleItems[AdminModuleInterface::SECTION_SALES];
        }

        // Admin-only sections — setPermission on the header hides it from editors (no empty headers).
        // The real gate is #[IsGranted('ROLE_ADMIN')] on each controller.
        yield MenuItem::section('Navigacija')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(MenuCrudController::class, 'Izbornici', 'fa fa-bars')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(MenuItemCrudController::class, 'Stavke izbornika', 'fa fa-list')->setPermission('ROLE_ADMIN');

        // SUSTAV — header has NO permission because Sigurnost (self-service 2FA) is editor-visible;
        // the admin-only items carry their own ROLE_ADMIN.
        yield MenuItem::section('Sustav');
        yield MenuItem::linkToRoute('Postavke', 'fa fa-gear', 'admin_settings')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkToRoute('Email predlošci', 'fa fa-envelope-open-text', 'admin_email_templates')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(UserCrudController::class, 'Korisnici', 'fa fa-users')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkToRoute('Sigurnost', 'fa fa-shield-halved', 'admin_security');

        yield MenuItem::section('Izgled')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(ThemeCrudController::class, 'Teme', 'fa fa-palette')->setPermission('ROLE_ADMIN');

        // MODULI — only the registry page (admin-only); module items now live in their own sections.
        yield MenuItem::section('Moduli')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkToRoute('Instalirani moduli', 'fa fa-puzzle-piece', 'admin_modules')->setPermission('ROLE_ADMIN');
    }
}
