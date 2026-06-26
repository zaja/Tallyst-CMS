<?php

namespace App\Controller\Admin;

use App\Entity\Menu;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class MenuCrudController extends AbstractCrudController
{
    use AdminCrudPolishTrait;

    public static function getEntityFqcn(): string
    {
        return Menu::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $this->inlineRowActions($crud
            ->setEntityLabelInSingular('admin.menu_entity.entity.singular')
            ->setEntityLabelInPlural('admin.menu_entity.entity.plural'));
    }

    public function configureActions(Actions $actions): Actions
    {
        return $this->addBackToListAction($actions);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'admin.menu_entity.field.name');
        yield TextField::new('location', 'admin.menu_entity.field.location')
            ->setHelp('admin.menu_entity.help.location');
    }
}
