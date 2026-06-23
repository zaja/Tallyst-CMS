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
}
