<?php

namespace App\Controller\Admin;

use App\Entity\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class MenuItemCrudController extends AbstractCrudController
{
    use AdminCrudPolishTrait;

    public static function getEntityFqcn(): string
    {
        return MenuItem::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $this->inlineRowActions($crud
            ->setEntityLabelInSingular('admin.menu_item.entity.singular')
            ->setEntityLabelInPlural('admin.menu_item.entity.plural')
            ->setDefaultSort(['position' => 'ASC']));
    }

    public function configureActions(Actions $actions): Actions
    {
        return $this->addBackToListAction($actions);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('menu', 'admin.menu_item.field.menu');
        yield TextField::new('label', 'admin.menu_item.field.label');
        yield AssociationField::new('page', 'admin.menu_item.field.page')->hideOnIndex()
            ->setHelp('admin.menu_item.help.page');
        yield TextField::new('url', 'admin.menu_item.field.url')->hideOnIndex()
            ->setHelp('admin.menu_item.help.url');
        yield AssociationField::new('parent', 'admin.menu_item.field.parent')->hideOnIndex();
        yield IntegerField::new('position', 'admin.menu_item.field.position');
    }
}
