<?php

namespace Tallyst\FormBuilder\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
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
}
