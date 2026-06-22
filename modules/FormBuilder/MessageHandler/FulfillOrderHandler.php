<?php

namespace Tallyst\FormBuilder\MessageHandler;

use App\Email\EmailSender;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Workflow\WorkflowInterface;
use Tallyst\FormBuilder\Entity\Order;
use Tallyst\FormBuilder\Message\FulfillOrderMessage;
use Tallyst\FormBuilder\Repository\OrderRepository;

/**
 * Fulfillment: send confirmation e-mails, then transition paid → fulfilled. Runs
 * async (see messenger.yaml). Idempotent — only a paid (not yet fulfilled) order is
 * processed. If the mailer throws, the message is retried and the order STAYS paid;
 * fulfillment never rolls back the money truth.
 *
 * Mails go through the EmailSender engine (editable templates); From stays unset
 * (DefaultFromListener / 553 lesson) and the send is async via the worker.
 *
 * Real product delivery (downloads/access) is a later iteration.
 */
#[AsMessageHandler]
class FulfillOrderHandler
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly EmailSender $emails,
        private readonly EntityManagerInterface $em,
        private readonly WorkflowInterface $orderStateMachine,
        #[Autowire('%env(ORDER_ADMIN_EMAIL)%')]
        private readonly string $adminEmail,
    ) {
    }

    public function __invoke(FulfillOrderMessage $message): void
    {
        $order = $this->orders->find($message->orderId);
        if (null === $order) {
            return;
        }

        // Idempotent: only fulfil a paid order, and only once.
        if (Order::STATUS_PAID !== $order->getStatus()) {
            return;
        }

        $this->sendCustomerEmail($order);
        $this->sendAdminEmail($order);

        if ($this->orderStateMachine->can($order, 'fulfill')) {
            $this->orderStateMachine->apply($order, 'fulfill');
            $this->em->flush();
        }
    }

    private function sendCustomerEmail(Order $order): void
    {
        $email = $order->getCustomerEmail();
        if (null === $email || '' === $email) {
            return;
        }

        $this->emails->send('order_confirmation', $this->tags($order), $email);
    }

    private function sendAdminEmail(Order $order): void
    {
        $this->emails->send('order_admin', $this->tags($order) + [
            'customer_email' => $order->getCustomerEmail() ?? '-',
        ], $this->adminEmail);
    }

    /**
     * @return array<string, string>
     */
    private function tags(Order $order): array
    {
        return [
            'order_id' => (string) $order->getId(),
            'amount' => number_format($order->getAmountMinor() / 100, 2, ',', '.'),
            'currency' => strtoupper($order->getCurrency()),
            'form_name' => $order->getForm()?->getName() ?? '-',
        ];
    }
}
