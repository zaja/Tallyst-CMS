<?php

namespace App\Controller;

use App\Repository\PostRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BlogController extends AbstractController
{
    #[Route('/blog', name: 'blog_index')]
    public function index(PostRepository $posts): Response
    {
        return $this->render('posts.html.twig', [
            'posts' => $posts->findPublished(),
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
        ]);
    }
}
