<?php

namespace App\Controller;

use App\Search\SearchService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public site search. The route always works (so bookmarked/direct links don't break); the header
 * search field is shown only when the "search_enabled" setting is on (a simple site can stay
 * field-free). Rendered through the active theme (search.html.twig). No conflict with page_show
 * `/{slug}` — this explicit route outranks its priority -100.
 */
class SearchController extends AbstractController
{
    #[Route('/pretraga', name: 'search')]
    public function search(Request $request, SearchService $search): Response
    {
        return $this->render('search.html.twig', $search->search((string) $request->query->get('q', '')));
    }
}
