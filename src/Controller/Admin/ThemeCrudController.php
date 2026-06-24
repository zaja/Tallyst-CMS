<?php

namespace App\Controller\Admin;

use App\Entity\Theme;
use App\Theme\ThemeDeletionGuard;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class ThemeCrudController extends AbstractCrudController
{
    public function __construct(private readonly ThemeDeletionGuard $guard)
    {
    }

    public static function getEntityFqcn(): string
    {
        return Theme::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        $hideWhenBlocked = fn (Action $a): Action => $a->displayIf(fn (Theme $t): bool => null === $this->guard->blockDelete($t));

        return $actions
            ->update(Crud::PAGE_INDEX, Action::DELETE, $hideWhenBlocked)
            ->update(Crud::PAGE_DETAIL, Action::DELETE, $hideWhenBlocked);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof Theme && null !== ($msg = $this->guard->blockDelete($entityInstance))) {
            $this->addFlash('danger', $msg);

            return; // no remove/flush — the theme stays
        }

        parent::deleteEntity($entityManager, $entityInstance);
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
