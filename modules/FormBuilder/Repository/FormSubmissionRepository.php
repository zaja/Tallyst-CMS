<?php

namespace Tallyst\FormBuilder\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\FormSubmission;

/**
 * @extends ServiceEntityRepository<FormSubmission>
 */
class FormSubmissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FormSubmission::class);
    }

    public function save(FormSubmission $submission, bool $flush = true): void
    {
        $this->getEntityManager()->persist($submission);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** Messages received through one form — the exact number named in the delete confirmation. */
    public function countForForm(FormDefinition $form): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.form = :form')
            ->setParameter('form', $form)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Message counts for MANY forms in ONE query (the forms list) — never a per-row count (N+1).
     * Forms with no messages are absent from the result; the caller defaults them to 0.
     *
     * @param list<int> $formIds
     *
     * @return array<int, int> formId => message count
     */
    public function countByFormIds(array $formIds): array
    {
        if ([] === $formIds) {
            return [];
        }

        $out = [];
        foreach ($this->createQueryBuilder('s')
            ->select('IDENTITY(s.form) AS formId, COUNT(s.id) AS c')
            ->where('s.form IN (:ids)')
            ->setParameter('ids', $formIds)
            ->groupBy('s.form')
            ->getQuery()->getResult() as $row) {
            $out[(int) $row['formId']] = (int) $row['c'];
        }

        return $out;
    }
}
