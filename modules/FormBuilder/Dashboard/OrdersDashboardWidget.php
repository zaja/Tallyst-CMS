<?php

namespace Tallyst\FormBuilder\Dashboard;

use App\Dashboard\DashboardWidgetInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Tallyst\FormBuilder\Controller\Admin\OrderCrudController;
use Tallyst\FormBuilder\Entity\Order;
use Tallyst\FormBuilder\Repository\OrderRepository;

/**
 * FormBuilder dashboard widget: revenue metrics + a 13-month daily revenue series (for the
 * client-side chart) + the "čeka isporuku" to-do (deep-linked to the paid-filtered order list) +
 * recent orders. ROLE_ADMIN only — revenue is admin data. Core never references Order; this widget
 * (FormBuilder → Core interface) is the boundary-preserving way it reaches the dashboard.
 */
class OrdersDashboardWidget implements DashboardWidgetInterface
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly AdminUrlGenerator $urls,
    ) {
    }

    public function getPosition(): int
    {
        return 10;
    }

    public function getRequiredRole(): ?string
    {
        return 'ROLE_ADMIN';
    }

    public function getTemplate(): string
    {
        return '@FormBuilder/admin/dashboard/orders_widget.html.twig';
    }

    public function getData(): array
    {
        $monthStart = new \DateTimeImmutable('first day of this month 00:00:00');
        $totals = $this->orders->revenueTotals();              // currency => minor (all time)
        $thisMonth = $this->orders->revenueTotals($monthStart); // currency => minor (this month)
        $byStatus = $this->orders->countByStatus();

        // Primary currency = the one with the most all-time revenue (chart shows it; cards show all).
        arsort($totals);
        $primary = array_key_first($totals); // null when there are no orders yet

        $series = [];
        if (null !== $primary) {
            foreach ($this->orders->revenueByDay(new \DateTimeImmutable('-13 months')) as $row) {
                if ($row['currency'] === $primary) {
                    $series[] = ['d' => $row['day'], 'total' => $row['total'], 'orders' => $row['orders']];
                }
            }
        }

        $awaiting = $byStatus[Order::STATUS_PAID] ?? 0;

        return [
            'revenueTotal' => $this->formatTotals($totals),
            'revenueThisMonth' => $this->formatTotals($thisMonth),
            'paidThisMonth' => $this->orders->countPaidSince($monthStart),
            'awaiting' => $awaiting,
            'awaitingUrl' => $this->urls->setController(OrderCrudController::class)->setAction(Action::INDEX)
                ->set('filters', ['status' => ['comparison' => '=', 'value' => Order::STATUS_PAID]])->generateUrl(),
            'chartCurrency' => null !== $primary ? strtoupper($primary) : '',
            'chartSeries' => $series,
            'multiCurrency' => \count($totals) > 1,
            'recent' => array_map(fn (Order $o): array => [
                'id' => $o->getId(),
                'date' => $o->getCreatedAt()?->format('d.m.Y. H:i'),
                'amount' => $this->money($o->getAmountMinor(), $o->getCurrency()),
                'status' => $o->getStatus(),
                'provider' => $o->getProvider(),
                'detailUrl' => $this->urls->setController(OrderCrudController::class)->setAction(Action::DETAIL)->setEntityId($o->getId())->generateUrl(),
            ], $this->orders->recentOrders(10)),
        ];
    }

    /**
     * @param array<string, int> $totals currency => minor
     *
     * @return list<string> formatted "1.234,50 EUR" (empty list when no revenue)
     */
    private function formatTotals(array $totals): array
    {
        $out = [];
        foreach ($totals as $currency => $minor) {
            $out[] = $this->money($minor, $currency);
        }

        return $out;
    }

    private function money(int $minor, string $currency): string
    {
        return number_format($minor / 100, 2, ',', '.').' '.strtoupper($currency);
    }
}
