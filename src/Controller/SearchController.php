<?php

namespace App\Controller;

use App\Search\SearchService;
use App\Settings\SettingsManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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

    /**
     * Live dropdown JSON (top 5) — reuses SearchService (same sanitisation). Gated on the same toggle:
     * when search is off the field is hidden, so this returns an empty set (consistent, no 404). Values
     * are plain data — the JS renders them via textContent / el.href (no innerHTML), so XSS-safe.
     */
    #[Route('/pretraga/live', name: 'search_live', methods: ['GET'])]
    public function live(Request $request, SearchService $search, SettingsManager $settings): JsonResponse
    {
        if (true !== $settings->get('search_enabled')) {
            return new JsonResponse(['results' => []]);
        }

        $found = $search->search((string) $request->query->get('q', ''), 5);
        $results = array_map(static fn (array $r): array => [
            'title' => $r['title'],
            'type' => $r['type'],
            'url' => $r['url'],
        ], $found['results']);

        return new JsonResponse(['results' => $results]);
    }
}
