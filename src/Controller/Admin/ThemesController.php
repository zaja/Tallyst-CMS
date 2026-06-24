<?php

namespace App\Controller\Admin;

use App\Entity\Theme;
use App\Repository\ThemeRepository;
use App\Theme\ThemeResolver;
use App\Theme\ThemeScanner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Theme management (V1): auto-detect themes from themes/, show them (thumbnail + metadata + active
 * badge), activate one. No browser upload (V2) and no CMS delete — themes are added/removed via FTP/git.
 * Replaces the old EA Theme CRUD ("add theme" flow). Admin-only; lives in the EA shell via the
 * dashboardControllerFqcn route default.
 */
#[Route('/admin/themes', defaults: ['dashboardControllerFqcn' => 'App\Controller\Admin\DashboardController'])]
#[IsGranted('ROLE_ADMIN')]
class ThemesController extends AbstractController
{
    public function __construct(
        private readonly ThemeScanner $scanner,
        private readonly ThemeResolver $resolver,
        private readonly ThemeRepository $themes,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'admin_themes', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/themes.html.twig', ['themes' => $this->scanner->scan()]);
    }

    #[Route('/{name}/activate', name: 'admin_themes_activate', methods: ['POST'])]
    public function activate(string $name, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('activate-theme-'.$name, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // Only a detected, valid theme can be activated — never point the site at a broken theme.
        if (!$this->scanner->isValidTheme($name)) {
            $this->addFlash('danger', 'Tema nije valjana ili ne postoji.');

            return $this->redirectToRoute('admin_themes');
        }

        $theme = $this->themes->findOneBy(['name' => $name]) ?? new Theme($name);
        foreach ($this->themes->findBy(['active' => true]) as $other) {
            $other->setActive(false);
        }
        $theme->setActive(true);
        $this->em->persist($theme);
        $this->em->flush();

        $this->addFlash('success', \sprintf('Tema "%s" je aktivirana.', $name));

        return $this->redirectToRoute('admin_themes');
    }

    /**
     * Streams themes/<name>/theme.png (lives outside public/, so it isn't web-published). Name is
     * regex-validated (no path traversal) and the file must exist, else 404 → the list shows a placeholder.
     */
    #[Route('/{name}/thumbnail', name: 'admin_themes_thumbnail', methods: ['GET'])]
    public function thumbnail(string $name): Response
    {
        if (1 !== preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
            throw $this->createNotFoundException();
        }
        $file = $this->resolver->getThemeDir($name).'/theme.png';
        if (!is_file($file)) {
            throw $this->createNotFoundException();
        }

        return new BinaryFileResponse($file, Response::HTTP_OK, ['Content-Type' => 'image/png']);
    }
}
