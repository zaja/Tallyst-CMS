<?php

namespace Tallyst\FormBuilder\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Tallyst\FormBuilder\Entity\FormDefinition;

/**
 * @extends ServiceEntityRepository<FormDefinition>
 */
class FormDefinitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FormDefinition::class);
    }

    public function findPublished(int $id): ?FormDefinition
    {
        return $this->findOneBy(['id' => $id, 'status' => FormDefinition::STATUS_PUBLISHED]);
    }

    public function save(FormDefinition $form, bool $flush = true): void
    {
        $this->getEntityManager()->persist($form);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(FormDefinition $form, bool $flush = true): void
    {
        $this->getEntityManager()->remove($form);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
