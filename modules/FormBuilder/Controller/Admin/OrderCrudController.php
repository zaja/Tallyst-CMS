<?php

namespace Tallyst\FormBuilder\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Factory\FilterFactory;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tallyst\FormBuilder\Entity\Order;
use Tallyst\FormBuilder\Payment\PaymentProcessorRegistry;
use Tallyst\FormBuilder\Repository\OrderRepository;
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
        #[Target('orderStateMachine')]
        private readonly WorkflowInterface $orderStateMachine,
        private readonly OrderMailer $mailer,
        private readonly EntityManagerInterface $em,
        private readonly PaymentProcessorRegistry $payments,
        private readonly OrderRepository $orders,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Order::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('admin.order.entity.singular')
            ->setEntityLabelInPlural('admin.order.entity.plural')
            ->setDefaultSort(['id' => 'DESC'])
            ->setPageTitle('detail', fn (Order $order): string => $this->translator->trans('admin.order.title.detail', ['%id%' => $order->getId()], 'admin'));
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status', 'admin.order.field.status')->setChoices([
                'admin.order.status.pending' => Order::STATUS_PENDING,
                'admin.order.status.paid' => Order::STATUS_PAID,
                'admin.order.status.fulfilled' => Order::STATUS_FULFILLED,
                'admin.order.status.refunded' => Order::STATUS_REFUNDED,
            ]))
            ->add(ChoiceFilter::new('provider', 'admin.order.field.provider')->setChoices(['Stripe' => 'stripe', 'PayPal' => 'paypal']))
            ->add(ChoiceFilter::new('paymentMode', 'admin.order.field.mode')->setChoices(['admin.order.mode.test' => 'test', 'admin.order.mode.live' => 'live']))
            // Date RANGE: clean date-only pickers; pick "između" in the comparison for od/do.
            // (Defaulting to "između" is unsafe — EA throws if BETWEEN is applied with empty dates.)
            ->add(DateTimeFilter::new('createdAt', 'admin.order.field.created_at')
                ->setFormTypeOption('value_type', DateType::class)
                ->setFormTypeOption('value_type_options', ['widget' => 'single_text']))
            ->add(TextFilter::new('variantLabel', 'admin.order.field.variant'));
    }

    public function configureActions(Actions $actions): Actions
    {
        $confirm = fn (string $key): array => ['onclick' => "return confirm('".$this->translator->trans($key, [], 'admin')."')"];

        $markFulfilled = Action::new('markFulfilled', 'admin.order.action.mark_fulfilled', 'fa fa-truck')
            ->linkToCrudAction('markFulfilled')
            ->displayIf(static fn (Order $order): bool => Order::STATUS_PAID === $order->getStatus())
            ->setHtmlAttributes($confirm('admin.order.confirm.mark_fulfilled'));

        $resend = Action::new('resendConfirmation', 'admin.order.action.resend', 'fa fa-envelope')
            ->linkToCrudAction('resendConfirmation')
            ->displayIf(static fn (Order $order): bool => \in_array($order->getStatus(), [Order::STATUS_PAID, Order::STATUS_FULFILLED], true))
            ->setHtmlAttributes($confirm('admin.order.confirm.resend'));

        $refund = Action::new('refundOrder', 'admin.order.action.refund', 'fa fa-rotate-left')
            ->linkToCrudAction('refundOrder')
            ->displayIf(static fn (Order $order): bool => \in_array($order->getStatus(), [Order::STATUS_PAID, Order::STATUS_FULFILLED], true))
            ->setHtmlAttributes($confirm('admin.order.confirm.refund'));

        $export = Action::new('exportCsv', 'admin.order.action.export', 'fa fa-file-csv')
            ->linkToCrudAction('exportCsv')
            ->createAsGlobalAction();

        $dashboard = Action::new('paymentDashboard', 'admin.order.action.payment_dashboard', 'fa fa-arrow-up-right-from-square')
            ->linkToUrl(fn (Order $order): string => (string) $this->payments->get($order->getProvider())->dashboardUrl($order))
            ->displayIf(fn (Order $order): bool => null !== $this->payments->get($order->getProvider())->dashboardUrl($order))
            ->setHtmlAttributes(['target' => '_blank', 'rel' => 'noopener']);

        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            // Make EA's built-in detail→index action an unmistakable "back to list" button.
            ->update(Crud::PAGE_DETAIL, Action::INDEX, fn (Action $a): Action => $a->setLabel('admin.order.action.back_to_list')->setIcon('fa fa-arrow-left'))
            ->add(Crud::PAGE_INDEX, $markFulfilled)
            ->add(Crud::PAGE_DETAIL, $markFulfilled)
            ->add(Crud::PAGE_INDEX, $resend)
            ->add(Crud::PAGE_DETAIL, $resend)
            ->add(Crud::PAGE_INDEX, $refund)
            ->add(Crud::PAGE_DETAIL, $refund)
            ->add(Crud::PAGE_DETAIL, $dashboard)
            ->add(Crud::PAGE_INDEX, $export);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', '#');
        yield DateTimeField::new('createdAt', 'admin.order.field.created_at');
        yield AssociationField::new('form', 'admin.order.field.form');
        yield TextField::new('amountFormatted', 'admin.order.field.amount');
        // Badge semantics: paid = awaiting delivery (a to-do), fulfilled = delivered.
        yield ChoiceField::new('status', 'admin.order.field.status')
            ->setChoices([
                'admin.order.status.pending' => Order::STATUS_PENDING,
                'admin.order.status.paid' => Order::STATUS_PAID,
                'admin.order.status.fulfilled' => Order::STATUS_FULFILLED,
                'admin.order.status.refunded' => Order::STATUS_REFUNDED,
            ])
            ->renderAsBadges([
                Order::STATUS_PENDING => 'secondary',
                Order::STATUS_PAID => 'warning',
                Order::STATUS_FULFILLED => 'success',
                Order::STATUS_REFUNDED => 'info',
            ]);
        // Provider at a glance in the list (badge, like status).
        yield ChoiceField::new('provider', 'admin.order.field.provider')
            ->setChoices(['Stripe' => 'stripe', 'PayPal' => 'paypal'])
            ->renderAsBadges(['stripe' => 'primary', 'paypal' => 'info']);
        yield TextField::new('variantLabel', 'admin.order.field.variant');
        yield TextField::new('customerEmail', 'admin.order.field.customer');

        yield TextField::new('netFormatted', 'admin.order.field.net')->onlyOnDetail();
        yield TextField::new('taxFormatted', 'admin.order.field.tax')->onlyOnDetail();
        yield TextField::new('taxRate', 'admin.order.field.tax_rate')->onlyOnDetail();
        yield TextField::new('taxName', 'admin.order.field.tax_name')->onlyOnDetail();
        yield TextField::new('customerIp', 'IP')->onlyOnDetail();

        yield TextField::new('paymentMode', 'admin.order.field.mode')->onlyOnDetail();
        yield TextField::new('providerSessionId', 'Checkout session')->onlyOnDetail();
        yield TextField::new('providerPaymentIntentId', 'Payment intent')->onlyOnDetail();
        yield TextareaField::new('submissionSummary', 'admin.order.field.submission')->onlyOnDetail();
    }

    /**
     * CSV export for the accountant (UTF-8 BOM so HR chars open correctly in Excel).
     * RESPECTS the active list filters/search: it rebuilds the SAME query the index uses
     * (createIndexQueryBuilder over the current SearchDto), so "filtered list → filtered export".
     * No active filter → SearchDto empty → all orders. Same button, contextual.
     */
    public function exportCsv(AdminContext $context): StreamedResponse
    {
        $fields = FieldCollection::new($this->configureFields(Crud::PAGE_INDEX));
        $filters = $this->container->get(FilterFactory::class)->create($context->getCrud()->getFiltersConfig(), $fields, $context->getEntity());
        $qb = $this->createIndexQueryBuilder($context->getSearch(), $context->getEntity(), $fields, $filters);
        /** @var Order[] $orders */
        $orders = $qb->getQuery()->getResult();

        $money = static fn (?int $minor): string => null === $minor ? '' : number_format($minor / 100, 2, '.', '');

        $response = new StreamedResponse(function () use ($orders, $money): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            // CSV headers are FIXED ENGLISH (never localised, NOT through trans) — a data export is a
            // global, predictable format for accountants / external systems / re-import, independent of
            // the admin's UI language. Deterministic regardless of app_locale.
            fputcsv($out, ['ID', 'Date', 'Gross', 'Net', 'Tax', 'Rate', 'Tax name', 'Currency', 'Provider', 'Mode', 'Status', 'E-mail', 'Variant', 'Customer data'], ',', '"', '');
            foreach ($orders as $o) {
                // Form data on one CSV line: flatten the summary's newlines (fputcsv handles commas/quotes).
                $customerData = str_replace(["\r\n", "\n", "\r"], '; ', $o->getSubmissionSummary());
                fputcsv($out, [
                    $o->getId(),
                    $o->getCreatedAt()?->format('Y-m-d H:i'),
                    $money($o->getAmountMinor()),
                    $money($o->getNetAmountMinor()),
                    $money($o->getTaxAmountMinor()),
                    $o->getTaxRate() ?? '',
                    $o->getTaxName() ?? '',
                    strtoupper($o->getCurrency()),
                    $o->getProvider(),
                    $o->getPaymentMode() ?? '',
                    $o->getStatus(),
                    $o->getCustomerEmail() ?? '',
                    $o->getVariantLabel() ?? '',
                    $customerData,
                ], ',', '"', '');
            }
            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="narudzbe.csv"');

        return $response;
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
            $this->addFlash('success', $this->translator->trans('admin.order.flash.marked_fulfilled', ['%id%' => $order->getId()], 'admin'));
        } else {
            $this->addFlash('warning', $this->translator->trans('admin.order.flash.mark_failed', ['%id%' => $order->getId(), '%status%' => $order->getStatus()], 'admin'));
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
            $this->addFlash('warning', $this->translator->trans('admin.order.flash.no_email', ['%id%' => $order->getId()], 'admin'));
        } else {
            $this->mailer->sendConfirmation($order);
            $this->addFlash('success', $this->translator->trans('admin.order.flash.resent', ['%id%' => $order->getId()], 'admin'));
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
            $this->addFlash('danger', $this->translator->trans('admin.order.flash.refund_failed', ['%error%' => $e->getMessage()], 'admin'));

            return $this->redirect($this->backToDetail($urls, $order));
        }

        // 2) Re-read committed state: if the resulting charge.refunded webhook already flipped the
        //    order (rare race), can('refund') is false → we skip apply+mail and avoid a 2nd mail.
        $this->em->refresh($order);

        if ($this->orderStateMachine->can($order, 'refund')) {
            $this->orderStateMachine->apply($order, 'refund');
            $this->em->flush();
            $this->mailer->sendRefunded($order);
            $this->addFlash('success', $this->translator->trans('admin.order.flash.refunded', ['%id%' => $order->getId()], 'admin'));
        } else {
            $this->addFlash('info', $this->translator->trans('admin.order.flash.already_refunded', ['%id%' => $order->getId()], 'admin'));
        }

        return $this->redirect($this->backToDetail($urls, $order));
    }

    private function backToDetail(AdminUrlGenerator $urls, Order $order): string
    {
        return $urls->setController(self::class)->setAction(Action::DETAIL)->setEntityId($order->getId())->generateUrl();
    }
}
