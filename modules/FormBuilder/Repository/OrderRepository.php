<?php

namespace Tallyst\FormBuilder\Repository;

use App\Entity\Customer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Tallyst\FormBuilder\Entity\FormDefinition;
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
    /**
     * Sales sitting under an address that no account has claimed yet. Matched case-insensitively,
     * because the same person can reach two payment providers with differently-cased spellings of
     * one mailbox, and splitting their history over that would be indefensible to them.
     *
     * @return list<Order>
     */
    public function findUnboundByEmail(string $email): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.customer IS NULL')
            ->andWhere('LOWER(o.customerEmail) = :email')
            ->setParameter('email', mb_strtolower(trim($email)))
            ->orderBy('o.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Everything this account owns, newest first — the customer's own list of purchases.
     *
     * @return list<Order>
     */
    public function findForCustomer(Customer $customer): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('o.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

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

    /** Orders placed through one form — the deletion guard's decision input (ANY status counts). */
    public function countForForm(FormDefinition $form): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->where('o.form = :form')
            ->setParameter('form', $form)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Order counts for MANY forms in ONE query (the forms list) — never a per-row count (N+1).
     * Forms with no orders are absent from the result; the caller defaults them to 0.
     *
     * @param list<int> $formIds
     *
     * @return array<int, int> formId => order count
     */
    public function countByFormIds(array $formIds): array
    {
        if ([] === $formIds) {
            return [];
        }

        $out = [];
        foreach ($this->createQueryBuilder('o')
            ->select('IDENTITY(o.form) AS formId, COUNT(o.id) AS c')
            ->where('o.form IN (:ids)')
            ->setParameter('ids', $formIds)
            ->groupBy('o.form')
            ->getQuery()->getResult() as $row) {
            $out[(int) $row['formId']] = (int) $row['c'];
        }

        return $out;
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
     * Daily revenue (minor) + order count per currency since a date — the chart series. GROUP BY day
     * in the DB.
     *
     * @return list<array{day: string, currency: string, total: int, orders: int}>
     */
    public function revenueByDay(\DateTimeImmutable $since): array
    {
        $rows = $this->createQueryBuilder('o')
            ->select("SUBSTRING(o.createdAt, 1, 10) AS day, o.currency AS currency, SUM(o.amountMinor) AS total, COUNT(o.id) AS orders")
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
            'orders' => (int) $r['orders'],
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
