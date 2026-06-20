<?php

namespace App\Controller\Admin;

use App\Module\ModuleRegistry;
use App\Module\ModuleStateManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Simple module-registry UI: lists installed modules and toggles their enabled
 * state. Lives under /admin so it is covered by the ROLE_ADMIN access control.
 */
class ModulesController extends AbstractController
{
    #[Route('/admin/modules', name: 'admin_modules', methods: ['GET'])]
    public function index(ModuleRegistry $modules, ModuleStateManager $state): Response
    {
        $rows = [];
        foreach ($modules->all() as $module) {
            $rows[] = [
                'name' => $module->getName(),
                'label' => $module->getLabel(),
                'version' => $module->getVersion(),
                'description' => $module->getDescription(),
                'enabled' => $state->isEnabled($module->getName()),
            ];
        }

        return $this->render('admin/modules.html.twig', ['modules' => $rows]);
    }

    #[Route('/admin/modules/{name}/toggle', name: 'admin_modules_toggle', methods: ['POST'])]
    public function toggle(string $name, Request $request, ModuleRegistry $modules, ModuleStateManager $state): Response
    {
        if (!$this->isCsrfTokenValid('toggle-module-'.$name, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (null === $modules->get($name)) {
            throw $this->createNotFoundException(sprintf('Module "%s" not found.', $name));
        }

        $state->setEnabled($name, !$state->isEnabled($name));
        $this->addFlash('success', sprintf('Modul "%s" je ažuriran.', $name));

        return $this->redirectToRoute('admin_modules');
    }
}
