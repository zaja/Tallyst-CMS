<?php

namespace App\Controller\Admin;

use App\Entity\Page;
use App\Entity\Post;
use App\Repository\PageRepository;
use App\Repository\PostRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * JSON feed for the editor's link picker ("internal link" tab) — published Pages + Posts the
 * author can link to without typing a URL. NOT an EA-shell page (returns JSON, no
 * dashboardControllerFqcn default). It powers content editing (a Page/Post link inside the
 * Tiptap editor), so it MUST stay ROLE_EDITOR — NOT ROLE_ADMIN — like the form/media pickers,
 * or editors can't link.
 *
 * The URL is a RELATIVE path resolved through the REAL router (page_show / blog_post, home slug
 * → the home route) — never hardcoded, host-independent — and the editor stores it as a plain
 * `<a href="…">` (decided: plain href, not a live [link page=N] reference). Only PUBLISHED rows
 * are listed (a draft has no public URL). Returns everything at once; the client filters by the
 * typed query (a `?q=` server search is a future option if the lists grow).
 */
#[Route('/admin/link-targets')]
#[IsGranted('ROLE_EDITOR')]
final class LinkTargetController extends AbstractController
{
    public function __construct(
        private readonly PageRepository $pages,
        private readonly PostRepository $posts,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[Route('', name: 'admin_link_targets', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $items = [];

        foreach ($this->pages->findBy(['status' => Page::STATUS_PUBLISHED], ['title' => 'ASC']) as $page) {
            $items[] = [
                'type' => 'page',
                'title' => $page->getTitle(),
                // The home page lives at "/" (the home route), not "/home" — mirror PageCrud preview.
                'url' => 'home' === $page->getSlug()
                    ? $this->urlGenerator->generate('home')
                    : $this->urlGenerator->generate('page_show', ['slug' => $page->getSlug()]),
            ];
        }

        foreach ($this->posts->findBy(['status' => Post::STATUS_PUBLISHED], ['publishedAt' => 'DESC']) as $post) {
            $items[] = [
                'type' => 'post',
                'title' => $post->getTitle(),
                'url' => $this->urlGenerator->generate('blog_post', ['slug' => $post->getSlug()]),
            ];
        }

        return $this->json(['items' => $items]);
    }
}
