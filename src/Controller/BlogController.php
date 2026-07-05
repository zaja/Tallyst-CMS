<?php

namespace App\Controller;

use App\Blog\PostPaginator;
use App\Repository\PostRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BlogController extends AbstractController
{
    #[Route('/blog', name: 'blog_index')]
    public function index(Request $request, PostPaginator $paginator): Response
    {
        return $this->render('posts.html.twig', [
            // Tolerant cast (not getInt, which 400s on non-numeric): 'abc' → 0 → clamped to 1.
            'page' => $paginator->paginate(null, (int) $request->query->get('page', 1)),
        ]);
    }

    #[Route('/blog/{slug}', name: 'blog_post', requirements: ['slug' => '[a-zA-Z0-9\-]+'])]
    public function show(string $slug, PostRepository $posts): Response
    {
        $post = $posts->findPublishedBySlug($slug);
        if (null === $post) {
            throw $this->createNotFoundException(sprintf('Post "%s" not found.', $slug));
        }

        return $this->render('post.html.twig', [
            'post' => $post,
            // Chronological neighbours (older/newer published posts), null at the ends.
            'prev' => $posts->findPreviousPublished($post),
            'next' => $posts->findNextPublished($post),
        ]);
    }
}
