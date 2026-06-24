<?php

namespace App\Controller;

use App\Repository\CategoryRepository;
use App\Repository\PageRepository;
use App\Repository\PostRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Crawler-facing SEO endpoints — public, on-demand, no auth. URLs are ABSOLUTE and built from
 * DEFAULT_URI (the canonical host), not the request host, so they're consistent even if the file is
 * fetched via another host. (Like webhooks, these must stay reachable under any future maintenance /
 * basic-auth — see the maintenance backlog.)
 */
class SitemapController extends AbstractController
{
    /** The Page served at `/` (PageController::home); emitted as `/`, never duplicated as `/home`. */
    private const HOME_SLUG = 'home';

    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        #[Autowire('%env(DEFAULT_URI)%')] private readonly string $baseUri,
    ) {
    }

    #[Route('/sitemap.xml', name: 'sitemap')]
    public function sitemap(PageRepository $pages, PostRepository $posts, CategoryRepository $categories): Response
    {
        $entries = [
            ['loc' => $this->abs('home')],
            ['loc' => $this->abs('blog_index')],
        ];

        foreach ($pages->findPublished() as $page) {
            if (self::HOME_SLUG === $page->getSlug()) {
                continue;
            }
            $entries[] = ['loc' => $this->abs('page_show', ['slug' => $page->getSlug()])];
        }
        foreach ($posts->findPublished() as $post) {
            $entries[] = [
                'loc' => $this->abs('blog_post', ['slug' => $post->getSlug()]),
                'lastmod' => $post->getPublishedAt()?->format('Y-m-d'),
            ];
        }
        foreach ($categories->findWithPublishedPosts() as $category) {
            $entries[] = ['loc' => $this->abs('category_show', ['slug' => $category->getSlug()])];
        }

        $response = $this->render('sitemap/sitemap.xml.twig', ['entries' => $entries]);
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');

        return $response;
    }

    #[Route('/robots.txt', name: 'robots')]
    public function robots(): Response
    {
        // Allow all public content; keep the back-office (incl. /admin/login, /admin/reset-password) out
        // of the index. Sitemap line uses the same absolute DEFAULT_URI base as the sitemap itself.
        $body = implode("\n", [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin',
            '',
            'Sitemap: '.$this->abs('sitemap'),
            '',
        ]);

        return new Response($body, Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    private function abs(string $route, array $params = []): string
    {
        return rtrim($this->baseUri, '/').$this->urls->generate($route, $params);
    }
}
