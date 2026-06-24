<?php

namespace App\Dashboard;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A block on the admin dashboard. Auto-tagged so modules contribute their own widgets without Core
 * depending on them (same IoC pattern as settings/email/menu sections) — e.g. FormBuilder ships the
 * orders/revenue widget, Core ships the content widget. The DashboardController sorts by position,
 * skips widgets the current user's role can't see, and renders each template with its data.
 */
#[AutoconfigureTag('app.dashboard_widget')]
interface DashboardWidgetInterface
{
    /** Lower = higher on the page. */
    public function getPosition(): int;

    /** Role required to see this widget (e.g. ROLE_ADMIN for revenue), or null for everyone. */
    public function getRequiredRole(): ?string;

    /** Twig template that renders the widget. */
    public function getTemplate(): string;

    /**
     * Variables passed to the template (already formatted for display).
     *
     * @return array<string, mixed>
     */
    public function getData(): array;
}
