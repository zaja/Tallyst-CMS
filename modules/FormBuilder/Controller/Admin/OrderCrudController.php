<?php

namespace Tallyst\FormBuilder\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Tallyst\FormBuilder\Entity\Order;

/**
 * Read-only admin view of orders. Orders are created by the public checkout flow and
 * advanced by the webhook/fulfillment — never edited by hand here — so create/edit/
 * delete are disabled and only list + detail remain.
 */
#[IsGranted('ROLE_ADMIN')]
class OrderCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Order::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Narudžba')
            ->setEntityLabelInPlural('Narudžbe')
            ->setDefaultSort(['id' => 'DESC'])
            ->setPageTitle('detail', static fn (Order $order): string => 'Narudžba #'.$order->getId());
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', '#');
        yield DateTimeField::new('createdAt', 'Datum');
        yield AssociationField::new('form', 'Forma');
        yield TextField::new('amountFormatted', 'Iznos');
        yield ChoiceField::new('status', 'Status')
            ->setChoices([
                'U obradi' => Order::STATUS_PENDING,
                'Plaćeno' => Order::STATUS_PAID,
                'Ispunjeno' => Order::STATUS_FULFILLED,
                'Refundirano' => Order::STATUS_REFUNDED,
            ])
            ->renderAsBadges([
                Order::STATUS_PENDING => 'secondary',
                Order::STATUS_PAID => 'success',
                Order::STATUS_FULFILLED => 'primary',
                Order::STATUS_REFUNDED => 'warning',
            ]);
        yield TextField::new('customerEmail', 'Kupac');

        yield TextField::new('provider', 'Provider')->onlyOnDetail();
        yield TextField::new('providerSessionId', 'Checkout session')->onlyOnDetail();
        yield TextField::new('providerPaymentIntentId', 'Payment intent')->onlyOnDetail();
        yield TextareaField::new('submissionSummary', 'Podaci forme')->onlyOnDetail();
    }
}
