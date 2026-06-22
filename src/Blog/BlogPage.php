<?php

namespace App\Blog;

use App\Entity\Post;

/**
 * One rendered page of the blog/archive listing: the slice of posts plus the (clamped) current
 * page and the computed last page, so templates can draw pagination without any further math.
 */
final readonly class BlogPage
{
    /**
     * @param Post[] $items
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $lastPage,
        public int $total,
    ) {
    }
}
