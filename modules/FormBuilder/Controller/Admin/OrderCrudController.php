<?php

namespace Tallyst\FormBuilder\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Workflow\WorkflowInterface;
use Tallyst\FormBuilder\Entity\Order;
use Tallyst\FormBuilder\Payment\PaymentProcessorRegistry;
use Tallyst\FormBuilder\Service\OrderMailer;

/**
 * Admin view of orders. Orders are created by the public checkout and marked `paid` by the
 * verified webhook — never created/edited by hand — so NEW/EDIT/DELETE stay disabled. Manual
 * fulfilment (Option B): the admin advances `paid → fulfilled` via the "Označi isporučeno" action
 * (through the state machine, never a manual status set), and can re-send the confirmation.
 */
#[IsGranted('ROLE_ADMIN')]
class OrderCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly WorkflowInterface $orderStateMachine,
        private readonly OrderMailer $mailer,
        private readonly EntityManagerInterface $em,
        private readonly PaymentProcessorRegistry $payments,
    ) {
    }

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
        $markFulfilled = Action::new('markFulfilled', 'Označi isporučeno', 'fa fa-truck')
            ->linkToCrudAction('markFulfilled')
            ->displayIf(static fn (Order $order): bool => Order::STATUS_PAID === $order->getStatus())
            ->setHtmlAttributes(['onclick' => "return confirm('Označiti ovu narudžbu kao isporučenu?')"]);

        $resend = Action::new('resendConfirmation', 'Pošalji ponovno potvrdu', 'fa fa-envelope')
            ->linkToCrudAction('resendConfirmation')
            ->displayIf(static fn (Order $order): bool => \in_array($order->getStatus(), [Order::STATUS_PAID, Order::STATUS_FULFILLED], true))
            ->setHtmlAttributes(['onclick' => "return confirm('Ponovno poslati potvrdu kupcu?')"]);

        $refund = Action::new('refundOrder', 'Refundiraj', 'fa fa-rotate-left')
            ->linkToCrudAction('refundOrder')
            ->displayIf(static fn (Order $order): bool => \in_array($order->getStatus(), [Order::STATUS_PAID, Order::STATUS_FULFILLED], true))
            ->setHtmlAttributes(['onclick' => "return confirm('Refundirati ovu narudžbu? Novac se vraća kupcu.')"]);

        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $markFulfilled)
            ->add(Crud::PAGE_DETAIL, $markFulfilled)
            ->add(Crud::PAGE_INDEX, $resend)
            ->add(Crud::PAGE_DETAIL, $resend)
            ->add(Crud::PAGE_INDEX, $refund)
            ->add(Crud::PAGE_DETAIL, $refund);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', '#');
        yield DateTimeField::new('createdAt', 'Datum');
        yield AssociationField::new('form', 'Forma');
        yield TextField::new('amountFormatted', 'Iznos');
        // Badge semantics: paid = awaiting delivery (a to-do), fulfilled = delivered.
        yield ChoiceField::new('status', 'Status')
            ->setChoices([
                'U obradi' => Order::STATUS_PENDING,
                'Čeka isporuku' => Order::STATUS_PAID,
                'Isporučeno' => Order::STATUS_FULFILLED,
                'Refundirano' => Order::STATUS_REFUNDED,
            ])
            ->renderAsBadges([
                Order::STATUS_PENDING => 'secondary',
                Order::STATUS_PAID => 'warning',
                Order::STATUS_FULFILLED => 'success',
                Order::STATUS_REFUNDED => 'info',
            ]);
        yield TextField::new('variantLabel', 'Varijanta');
        yield TextField::new('customerEmail', 'Kupac');

        yield TextField::new('provider', 'Provider')->onlyOnDetail();
        yield TextField::new('providerSessionId', 'Checkout session')->onlyOnDetail();
        yield TextField::new('providerPaymentIntentId', 'Payment intent')->onlyOnDetail();
        yield TextareaField::new('submissionSummary', 'Podaci forme')->onlyOnDetail();
    }

    public function markFulfilled(AdminContext $context, AdminUrlGenerator $urls): Response
    {
        $order = $context->getEntity()->getInstance();
        if (!$order instanceof Order) {
            throw $this->createNotFoundException();
        }

        if ($this->orderStateMachine->can($order, 'fulfill')) {
            $this->orderStateMachine->apply($order, 'fulfill');
            $this->em->flush();
            $this->mailer->sendDelivered($order);
            $this->addFlash('success', \sprintf('Narudžba #%d označena kao isporučena.', $order->getId()));
        } else {
            $this->addFlash('warning', \sprintf('Narudžbu #%d nije moguće označiti isporučenom (status: %s).', $order->getId(), $order->getStatus()));
        }

        return $this->redirect($this->backToDetail($urls, $order));
    }

    public function resendConfirmation(AdminContext $context, AdminUrlGenerator $urls): Response
    {
        $order = $context->getEntity()->getInstance();
        if (!$order instanceof Order) {
            throw $this->createNotFoundException();
        }

        if (null === $order->getCustomerEmail() || '' === $order->getCustomerEmail()) {
            $this->addFlash('warning', \sprintf('Narudžba #%d nema e-mail kupca.', $order->getId()));
        } else {
            $this->mailer->sendConfirmation($order);
            $this->addFlash('success', \sprintf('Potvrda za narudžbu #%d ponovno poslana.', $order->getId()));
        }

        return $this->redirect($this->backToDetail($urls, $order));
    }

    public function refundOrder(AdminContext $context, AdminUrlGenerator $urls): Response
    {
        $order = $context->getEntity()->getInstance();
        if (!$order instanceof Order) {
            throw $this->createNotFoundException();
        }

        // 1) Provider refund first. On any provider error → flash + bail (no state change, no 500).
        try {
            $this->payments->get($order->getProvider())->refund($order);
        } catch (\Throwable $e) {
            $this->addFlash('danger', \sprintf('Refund nije uspio: %s', $e->getMessage()));

            return $this->redirect($this->backToDetail($urls, $order));
        }

        // 2) Re-read committed state: if the resulting charge.refunded webhook already flipped the
        //    order (rare race), can('refund') is false → we skip apply+mail and avoid a 2nd mail.
        $this->em->refresh($order);

        if ($this->orderStateMachine->can($order, 'refund')) {
            $this->orderStateMachine->apply($order, 'refund');
            $this->em->flush();
            $this->mailer->sendRefunded($order);
            $this->addFlash('success', \sprintf('Narudžba #%d je refundirana.', $order->getId()));
        } else {
            $this->addFlash('info', \sprintf('Narudžba #%d je već refundirana.', $order->getId()));
        }

        return $this->redirect($this->backToDetail($urls, $order));
    }

    private function backToDetail(AdminUrlGenerator $urls, Order $order): string
    {
        return $urls->setController(self::class)->setAction(Action::DETAIL)->setEntityId($order->getId())->generateUrl();
    }
}
