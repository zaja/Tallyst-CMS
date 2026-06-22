<?php

namespace App\Blog;

use App\Entity\Category;
use App\Repository\PostRepository;
use App\Settings\SettingsManager;

/**
 * Shared pagination for the blog index AND category archives, so the published scope and the
 * page/per-page handling live in ONE place (both controllers call this). All the clamping that
 * keeps junk input from 500-ing or breaking the SQL LIMIT happens here, authoritatively.
 */
class PostPaginator
{
    private const PER_PAGE_DEFAULT = 9;
    private const PER_PAGE_MIN = 1;
    private const PER_PAGE_MAX = 50;

    public function __construct(
        private readonly PostRepository $posts,
        private readonly SettingsManager $settings,
    ) {
    }

    public function paginate(?Category $category, int $rawPage): BlogPage
    {
        $perPage = $this->perPage();
        // ?page=abc → (int) 0 → 1; ?page=0 / negative → 1.
        $page = max(1, $rawPage);

        $paginator = $this->posts->paginatePublished($category, ($page - 1) * $perPage, $perPage);
        $total = \count($paginator);
        $lastPage = max(1, (int) ceil($total / $perPage));

        // Out-of-range page → show the last page instead of an empty slice (never a 500).
        if ($page > $lastPage) {
            $page = $lastPage;
            $paginator = $this->posts->paginatePublished($category, ($page - 1) * $perPage, $perPage);
        }

        return new BlogPage(iterator_to_array($paginator), $page, $lastPage, $total);
    }

    private function perPage(): int
    {
        $value = (int) ($this->settings->get('blog_posts_per_page') ?? self::PER_PAGE_DEFAULT);

        return max(self::PER_PAGE_MIN, min(self::PER_PAGE_MAX, $value));
    }
}
