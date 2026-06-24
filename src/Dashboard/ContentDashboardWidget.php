<?php

namespace App\Dashboard;

use App\Controller\Admin\PostCrudController;
use App\Entity\Post;
use App\Repository\PageRepository;
use App\Repository\PostRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

/**
 * Core dashboard widget: content overview (counts) + the most recent posts with edit links.
 * Visible to everyone in the back-office (no financial data) — editors manage content.
 */
class ContentDashboardWidget implements DashboardWidgetInterface
{
    public function __construct(
        private readonly PostRepository $posts,
        private readonly PageRepository $pages,
        private readonly AdminUrlGenerator $urls,
    ) {
    }

    public function getPosition(): int
    {
        return 20;
    }

    public function getRequiredRole(): ?string
    {
        return null; // content is editor-visible
    }

    public function getTemplate(): string
    {
        return 'admin/dashboard/content_widget.html.twig';
    }

    public function getData(): array
    {
        $recent = array_map(fn (Post $p): array => [
            'title' => $p->getTitle(),
            'status' => $p->getStatus(),
            'published' => Post::STATUS_PUBLISHED === $p->getStatus(),
            'date' => $p->getPublishedAt()?->format('d.m.Y.'),
            'editUrl' => $this->urls->setController(PostCrudController::class)->setAction(Action::EDIT)->setEntityId($p->getId())->generateUrl(),
        ], $this->posts->recent(10));

        return [
            'posts' => $recent,
            'postCount' => $this->posts->count([]),
            'pageCount' => $this->pages->count([]),
        ];
    }
}
