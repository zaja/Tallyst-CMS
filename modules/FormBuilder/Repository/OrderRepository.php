<?php

namespace Tallyst\FormBuilder\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Tallyst\FormBuilder\Entity\Order;

/**
 * @extends ServiceEntityRepository<Order>
 */
class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    public function findOneByProviderSessionId(string $sessionId): ?Order
    {
        return $this->findOneBy(['providerSessionId' => $sessionId]);
    }

    /**
     * Match a refund webhook (charge.refunded carries the payment_intent). The PI column isn't
     * unique, but in this flow one PI = one payment = one order, so the first match is correct.
     */
    public function findOneByProviderPaymentIntentId(string $paymentIntentId): ?Order
    {
        return $this->findOneBy(['providerPaymentIntentId' => $paymentIntentId]);
    }

    /**
     * All orders, newest first — for the accountant CSV export.
     *
     * @return Order[]
     */
    public function findAllOrderedByIdDesc(): array
    {
        return $this->findBy([], ['id' => 'DESC']);
    }

    public function save(Order $order, bool $flush = true): void
    {
        $this->getEntityManager()->persist($order);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    // --- Dashboard aggregation (revenue = paid + fulfilled; refunded EXCLUDED — money returned) ---

    /** @var list<string> revenue-bearing statuses */
    private const REVENUE_STATUSES = [Order::STATUS_PAID, Order::STATUS_FULFILLED];

    /**
     * Revenue summed per currency (minor units), optionally since a date. Aggregated in the DB.
     *
     * @return array<string, int> e.g. ['eur' => 12300]
     */
    public function revenueTotals(?\DateTimeImmutable $since = null): array
    {
        $qb = $this->createQueryBuilder('o')
            ->select('o.currency AS currency, SUM(o.amountMinor) AS total')
            ->where('o.status IN (:statuses)')
            ->setParameter('statuses', self::REVENUE_STATUSES)
            ->groupBy('o.currency');
        if (null !== $since) {
            $qb->andWhere('o.createdAt >= :since')->setParameter('since', $since);
        }

        $out = [];
        foreach ($qb->getQuery()->getResult() as $row) {
            $out[(string) $row['currency']] = (int) $row['total'];
        }

        return $out;
    }

    /** Count of revenue-bearing (paid+fulfilled) orders since a date. */
    public function countPaidSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->where('o.status IN (:statuses)')
            ->andWhere('o.createdAt >= :since')
            ->setParameter('statuses', self::REVENUE_STATUSES)
            ->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Order count per status (for the "awaiting delivery" = paid card, etc.).
     *
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        $out = [];
        foreach ($this->createQueryBuilder('o')
            ->select('o.status AS status, COUNT(o.id) AS c')
            ->groupBy('o.status')
            ->getQuery()->getResult() as $row) {
            $out[(string) $row['status']] = (int) $row['c'];
        }

        return $out;
    }

    /**
     * Daily revenue (minor) per currency since a date — the chart series. GROUP BY day in the DB.
     *
     * @return list<array{day: string, currency: string, total: int}>
     */
    public function revenueByDay(\DateTimeImmutable $since): array
    {
        $rows = $this->createQueryBuilder('o')
            ->select("SUBSTRING(o.createdAt, 1, 10) AS day, o.currency AS currency, SUM(o.amountMinor) AS total")
            ->where('o.status IN (:statuses)')
            ->andWhere('o.createdAt >= :since')
            ->setParameter('statuses', self::REVENUE_STATUSES)
            ->setParameter('since', $since)
            ->groupBy('day')
            ->addGroupBy('o.currency')
            ->orderBy('day', 'ASC')
            ->getQuery()->getResult();

        return array_map(static fn (array $r): array => [
            'day' => (string) $r['day'],
            'currency' => (string) $r['currency'],
            'total' => (int) $r['total'],
        ], $rows);
    }

    /**
     * Most recent orders for the dashboard list.
     *
     * @return Order[]
     */
    public function recentOrders(int $limit = 10): array
    {
        return $this->findBy([], ['id' => 'DESC'], $limit);
    }
}
