<?php

namespace App\Controller;

use App\Entity\Page;
use App\Repository\PageRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PageController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function home(PageRepository $pages): Response
    {
        $page = $pages->findPublishedBySlug('home');

        // Fresh install with no "home" page yet — show a transient welcome page.
        if (null === $page) {
            $page = (new Page('Dobrodošli', 'home'))
                ->setContent('<p>Tallyst CMS je uspješno postavljen.</p>')
                ->setStatus(Page::STATUS_PUBLISHED);
        }

        return $this->renderPage($page);
    }

    /**
     * Catch-all front route. Low priority so real routes (e.g. /blog) win.
     */
    #[Route('/{slug}', name: 'page_show', requirements: ['slug' => '(?!admin)[a-zA-Z0-9\-]+'], priority: -100)]
    public function show(string $slug, PageRepository $pages): Response
    {
        $page = $pages->findPublishedBySlug($slug);
        if (null === $page) {
            throw $this->createNotFoundException(sprintf('Page "%s" not found.', $slug));
        }

        return $this->renderPage($page);
    }

    private function renderPage(Page $page): Response
    {
        return $this->render($page->getTemplate() ?: 'page.html.twig', [
            'page' => $page,
        ]);
    }
}
