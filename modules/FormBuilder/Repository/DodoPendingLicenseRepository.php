<?php

namespace Tallyst\FormBuilder\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Tallyst\FormBuilder\Entity\DodoPendingLicense;

/**
 * @extends ServiceEntityRepository<DodoPendingLicense>
 */
class DodoPendingLicenseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DodoPendingLicense::class);
    }

    public function findByPaymentId(string $paymentId): ?DodoPendingLicense
    {
        return $this->findOneBy(['paymentId' => $paymentId]);
    }

    /**
     * Store a licence keyed by payment_id, idempotently. UNIQUE(payment_id) means a duplicate
     * entitlement webhook is a no-op (we keep the first). Persist only (caller flushes).
     */
    public function upsert(string $paymentId, string $licenseKey): void
    {
        if (null !== $this->findByPaymentId($paymentId)) {
            return;
        }
        $this->getEntityManager()->persist(new DodoPendingLicense($paymentId, $licenseKey));
    }

    public function remove(DodoPendingLicense $pending): void
    {
        $this->getEntityManager()->remove($pending);
    }
}
