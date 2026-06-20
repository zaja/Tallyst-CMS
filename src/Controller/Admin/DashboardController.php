<?php

namespace App\Controller\Admin;

use App\Entity\Category;
use App\Entity\Menu;
use App\Entity\MenuItem as MenuItemEntity;
use App\Entity\Page;
use App\Entity\Post;
use App\Entity\Setting;
use App\Entity\Theme;
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

        yield MenuItem::section('Sadržaj');
        yield MenuItem::linkToCrud('Stranice', 'fa fa-file-lines', Page::class);
        yield MenuItem::linkToCrud('Objave', 'fa fa-newspaper', Post::class);
        yield MenuItem::linkToCrud('Kategorije', 'fa fa-tags', Category::class);

        yield MenuItem::section('Navigacija');
        yield MenuItem::linkToCrud('Izbornici', 'fa fa-bars', Menu::class);
        yield MenuItem::linkToCrud('Stavke izbornika', 'fa fa-list', MenuItemEntity::class);

        yield MenuItem::section('Izgled');
        yield MenuItem::linkToCrud('Teme', 'fa fa-palette', Theme::class);

        yield MenuItem::section('Sustav');
        yield MenuItem::linkToCrud('Postavke', 'fa fa-gear', Setting::class);

        // Modules surface their own admin entries here, built dynamically from the
        // registry. Disabled modules are skipped.
        yield MenuItem::section('Moduli');
        yield MenuItem::linkToRoute('Instalirani moduli', 'fa fa-puzzle-piece', 'admin_modules');
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
