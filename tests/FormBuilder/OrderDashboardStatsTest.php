<?php

namespace App\Tests\FormBuilder;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\Order;
use Tallyst\FormBuilder\Repository\OrderRepository;

/**
 * Dashboard revenue aggregation: revenue = paid + fulfilled only (refunded/pending EXCLUDED, since the
 * money was returned / never captured), summed per currency in the DB, with correct month/day grouping.
 */
class OrderDashboardStatsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private OrderRepository $orders;
    /** @var int[] */
    private array $orderIds = [];
    private ?int $formId = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->orders = self::getContainer()->get(OrderRepository::class);

        $form = (new FormDefinition())->setName('Stats')->setSlug('stats-'.bin2hex(random_bytes(4)));
        $this->em->persist($form);
        $this->em->flush();
        $this->formId = $form->getId();

        // [status, currency, minor, created_at] — one paid order is LAST month for the boundary test.
        $thisMonth = (new \DateTimeImmutable('first day of this month 00:00:00'))->modify('+2 days')->format('Y-m-d H:i:s');
        $lastMonth = (new \DateTimeImmutable('first day of last month 00:00:00'))->modify('+2 days')->format('Y-m-d H:i:s');
        $rows = [
            [Order::STATUS_PAID, 'eur', 1000, $thisMonth],
            [Order::STATUS_FULFILLED, 'eur', 2000, $thisMonth],
            [Order::STATUS_REFUNDED, 'eur', 5000, $thisMonth], // excluded from revenue
            [Order::STATUS_PENDING, 'eur', 9999, $thisMonth],  // excluded from revenue
            [Order::STATUS_PAID, 'usd', 3000, $thisMonth],
            [Order::STATUS_PAID, 'eur', 4000, $lastMonth],     // all-time yes, this-month no
        ];
        foreach ($rows as [$status, $currency, $minor, $when]) {
            $o = (new Order())->setForm($form)->setStatus($status)->setProvider('stripe')
                ->setAmountMinor($minor)->setCurrency($currency);
            $this->em->persist($o);
            $this->em->flush();
            $this->em->getConnection()->executeStatement('UPDATE fb_order SET created_at = ? WHERE id = ?', [$when, $o->getId()]);
            $this->orderIds[] = $o->getId();
        }
        $this->em->clear();
    }

    public function testRevenueTotalsAllTimePerCurrencyExcludesRefundedAndPending(): void
    {
        $totals = $this->orders->revenueTotals();

        self::assertSame(7000, $totals['eur'] ?? null, '1000 + 2000 + 4000; refunded 5000 & pending 9999 excluded');
        self::assertSame(3000, $totals['usd'] ?? null);
    }

    public function testRevenueTotalsThisMonthExcludesLastMonth(): void
    {
        $monthStart = new \DateTimeImmutable('first day of this month 00:00:00');
        $totals = $this->orders->revenueTotals($monthStart);

        self::assertSame(3000, $totals['eur'] ?? null, 'this month: 1000 + 2000 (the 4000 is last month)');
        self::assertSame(3000, $totals['usd'] ?? null);
    }

    public function testCountByStatus(): void
    {
        $byStatus = $this->orders->countByStatus();

        self::assertSame(3, $byStatus[Order::STATUS_PAID] ?? 0);
        self::assertSame(1, $byStatus[Order::STATUS_FULFILLED] ?? 0);
        self::assertSame(1, $byStatus[Order::STATUS_REFUNDED] ?? 0);
        self::assertSame(1, $byStatus[Order::STATUS_PENDING] ?? 0);
    }

    public function testCountPaidSinceCountsRevenueOrdersOnly(): void
    {
        $monthStart = new \DateTimeImmutable('first day of this month 00:00:00');

        // this month: 2 eur (paid+fulfilled) + 1 usd paid = 3; refunded/pending excluded; last-month paid excluded.
        self::assertSame(3, $this->orders->countPaidSince($monthStart));
    }

    public function testRevenueByDayGroupsByDayAndCurrency(): void
    {
        $rows = $this->orders->revenueByDay(new \DateTimeImmutable('-13 months'));

        // Every row is a revenue order; refunded/pending never appear.
        $sum = array_sum(array_map(static fn (array $r): int => $r['total'], $rows));
        self::assertSame(10000, $sum, 'all revenue (7000 eur + 3000 usd) across the daily buckets');
        foreach ($rows as $r) {
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $r['day']);
            self::assertContains($r['currency'], ['eur', 'usd']);
        }

        // Order count per bucket (the chart's second series): 4 revenue orders total
        // (2 eur this-month + 1 usd this-month + 1 eur last-month); refunded/pending excluded.
        $orders = array_sum(array_map(static fn (array $r): int => $r['orders'], $rows));
        self::assertSame(4, $orders, 'paid+fulfilled only; refunded & pending excluded');
        $eurThisMonth = array_filter($rows, static fn (array $r): bool => 'eur' === $r['currency'] && 3000 === $r['total']);
        self::assertCount(1, $eurThisMonth, 'the two same-day eur revenue orders group into one bucket');
        self::assertSame(2, reset($eurThisMonth)['orders'], 'that bucket counts 2 orders');
    }

    public function testRecentOrdersLimitAndOrder(): void
    {
        $recent = $this->orders->recentOrders(2);

        self::assertCount(2, $recent);
        self::assertGreaterThan($recent[1]->getId(), $recent[0]->getId(), 'newest first');
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        foreach ($this->orderIds as $id) {
            $conn->executeStatement('DELETE FROM fb_order WHERE id = ?', [$id]);
        }
        if (null !== $this->formId) {
            $conn->executeStatement('DELETE FROM fb_form WHERE id = ?', [$this->formId]);
        }
        parent::tearDown();
    }
}
