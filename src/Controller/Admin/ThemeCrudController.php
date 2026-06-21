<?php

namespace App\Controller\Admin;

use App\Entity\Theme;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class ThemeCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Theme::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Tema')
            ->setEntityLabelInPlural('Teme');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Naziv (folder)')
            ->setHelp('Mora odgovarati nazivu mape pod themes/.');
        yield TextField::new('label', 'Oznaka');
        yield BooleanField::new('active', 'Aktivna');
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof Theme) {
            $this->enforceSingleActive($entityManager, $entityInstance);
        }

        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof Theme) {
            $this->enforceSingleActive($entityManager, $entityInstance);
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    /**
     * Keep at most one active theme: when this one is active, deactivate the rest
     * in-memory so the change flushes together (avoids DQL/UnitOfWork desync).
     */
    private function enforceSingleActive(EntityManagerInterface $em, Theme $current): void
    {
        if (!$current->isActive()) {
            return;
        }

        foreach ($em->getRepository(Theme::class)->findBy(['active' => true]) as $other) {
            if ($other !== $current) {
                $other->setActive(false);
            }
        }
    }
}
