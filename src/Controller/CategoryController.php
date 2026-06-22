<?php

namespace App\Controller;

use App\Blog\PostPaginator;
use App\Repository\CategoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CategoryController extends AbstractController
{
    /**
     * Category archive: the category's PUBLISHED posts, paginated (same published scope as the
     * blog index). Two-segment path, so the single-segment /{slug} catch-all can't swallow it.
     */
    #[Route('/kategorija/{slug}', name: 'category_show', requirements: ['slug' => '[a-zA-Z0-9\-]+'])]
    public function show(string $slug, Request $request, CategoryRepository $categories, PostPaginator $paginator): Response
    {
        $category = $categories->findOneBySlug($slug);
        if (null === $category) {
            throw $this->createNotFoundException(sprintf('Category "%s" not found.', $slug));
        }

        return $this->render('category.html.twig', [
            'category' => $category,
            // Tolerant cast (not getInt, which 400s on non-numeric): 'abc' → 0 → clamped to 1.
            'page' => $paginator->paginate($category, (int) $request->query->get('page', 1)),
        ]);
    }
}
