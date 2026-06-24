<?php

namespace App\Search;

use App\Repository\CategoryRepository;
use App\Repository\PageRepository;
use App\Repository\PostRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Public FULLTEXT search over published content (Pages, Posts, Categories), ranked by relevance and
 * mixed across types (the visitor searches a term, not a type). Self-hosted, zero external deps.
 *
 * Safety: the query is tokenised to word characters only (`[\p{L}\p{N}]+`), so boolean operators
 * (+ - * " ( ) …) and any injection are dropped before the AGAINST string is rebuilt; the SQL is
 * parameterised. Snippets escape their text before injecting <mark>.
 */
class SearchService
{
    private const MIN_TOKEN = 3;        // matches innodb_ft_min_token_size default — shorter tokens aren't indexed
    private const PER_TYPE_LIMIT = 25;
    private const TOTAL_LIMIT = 25;
    private const SNIPPET_LEN = 160;

    public function __construct(
        private readonly PageRepository $pages,
        private readonly PostRepository $posts,
        private readonly CategoryRepository $categories,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @param int|null $limit max merged results (default TOTAL_LIMIT; the live dropdown passes 5)
     *
     * @return array{state: 'ok'|'empty'|'short', query: string, results: list<array{type:string, title:string, url:string, snippet:string, score:float}>}
     */
    public function search(string $query, ?int $limit = null): array
    {
        $query = trim($query);
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($query), $m);
        $tokens = array_values(array_filter($m[0] ?? [], static fn (string $t): bool => mb_strlen($t) >= self::MIN_TOKEN));

        if ([] === $tokens) {
            // No indexable token: empty input → "empty"; a too-short attempt (e.g. "tv") → "short".
            return ['state' => '' === $query ? 'empty' : 'short', 'query' => $query, 'results' => []];
        }

        $boolean = implode(' ', array_map(static fn (string $t): string => $t.'*', $tokens));

        $results = [];
        foreach ($this->pages->searchPublished($boolean, self::PER_TYPE_LIMIT) as $r) {
            $results[] = $this->result('Stranica', 'page_show', $r['slug'], $r['title'], $r['content'], (float) $r['score'], $tokens);
        }
        foreach ($this->posts->searchPublished($boolean, self::PER_TYPE_LIMIT) as $r) {
            $body = ('' !== (string) $r['excerpt']) ? $r['excerpt'] : $r['content'];
            $results[] = $this->result('Objava', 'blog_post', $r['slug'], $r['title'], $body, (float) $r['score'], $tokens);
        }
        foreach ($this->categories->search($boolean, self::PER_TYPE_LIMIT) as $r) {
            $results[] = $this->result('Kategorija', 'category_show', $r['slug'], $r['name'], $r['description'], (float) $r['score'], $tokens);
        }

        usort($results, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $results = \array_slice($results, 0, $limit ?? self::TOTAL_LIMIT);

        return ['state' => [] === $results ? 'empty' : 'ok', 'query' => $query, 'results' => $results];
    }

    /**
     * @param string[] $tokens
     *
     * @return array{type:string, title:string, url:string, snippet:string, snippetText:string, score:float}
     */
    private function result(string $type, string $route, string $slug, string $title, ?string $body, float $score, array $tokens): array
    {
        $text = $this->excerpt((string) $body, $tokens);

        return [
            'type' => $type,
            'title' => $title,
            'url' => $this->urls->generate($route, ['slug' => $slug]),
            // Page uses the highlighted HTML (|raw); the live dropdown uses the plain text (textContent).
            'snippet' => '' === $text ? '' : $this->highlight(htmlspecialchars($text, \ENT_QUOTES), $tokens),
            'snippetText' => $text,
            'score' => $score,
        ];
    }

    /**
     * A short PLAIN-text excerpt (no HTML, no highlight) around the first matching token. Shared by the
     * page snippet (which then escapes + highlights it) and the live dropdown (which renders it as-is via
     * textContent — so it must stay plain).
     *
     * @param string[] $tokens
     */
    private function excerpt(string $body, array $tokens): string
    {
        $plain = strip_tags((string) preg_replace('/\[[^\]]*\]/', ' ', $body));
        $plain = trim((string) preg_replace('/\s+/', ' ', $plain));
        if ('' === $plain) {
            return '';
        }

        $pos = false;
        foreach ($tokens as $t) {
            if (false !== ($p = mb_stripos($plain, $t))) {
                $pos = $p;
                break;
            }
        }

        $start = false === $pos ? 0 : max(0, $pos - 60);
        $window = mb_substr($plain, $start, self::SNIPPET_LEN);

        return ($start > 0 ? '… ' : '').$window.(mb_strlen($plain) > $start + self::SNIPPET_LEN ? ' …' : '');
    }

    /**
     * Wrap each token in <mark> on ALREADY-ESCAPED text — XSS-safe (tokens are word-chars, so escaping
     * doesn't alter them; only <mark> is injected). The page template prints the result with |raw.
     *
     * @param string[] $tokens
     */
    private function highlight(string $escaped, array $tokens): string
    {
        foreach ($tokens as $t) {
            $escaped = (string) preg_replace('/('.preg_quote($t, '/').')/iu', '<mark>$1</mark>', $escaped);
        }

        return $escaped;
    }
}
