<?php

namespace Tallyst\FormBuilder\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
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
 * Real product delivery (downloads/access) is a later iteration.
 */
#[AsMessageHandler]
class FulfillOrderHandler
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly MailerInterface $mailer,
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

        // No explicit From: the global DefaultFromListener fills it from the configured
        // email identity (mail_from_email). Hardcoding a From here meant order mail used an
        // address the SMTP account doesn't own (e.g. noreply@…), which real SMTP servers
        // reject (553 "Sender address rejected"). One identity, one source of truth.
        $this->mailer->send(
            (new Email())
                ->to($email)
                ->subject(\sprintf('Potvrda narudžbe #%d', $order->getId()))
                ->text(\sprintf(
                    "Hvala na narudžbi!\n\nNarudžba #%d\nIznos: %s %s\nStatus: plaćeno\n\nTallyst",
                    $order->getId(),
                    number_format($order->getAmountMinor() / 100, 2, ',', '.'),
                    strtoupper($order->getCurrency()),
                )),
        );
    }

    private function sendAdminEmail(Order $order): void
    {
        $this->mailer->send(
            (new Email())
                ->to($this->adminEmail)
                ->subject(\sprintf('Nova plaćena narudžba #%d', $order->getId()))
                ->text(\sprintf(
                    "Nova plaćena narudžba.\n\nNarudžba #%d\nForma: %s\nIznos: %s %s\nKupac: %s",
                    $order->getId(),
                    $order->getForm()?->getName() ?? '-',
                    number_format($order->getAmountMinor() / 100, 2, ',', '.'),
                    strtoupper($order->getCurrency()),
                    $order->getCustomerEmail() ?? '-',
                )),
        );
    }
}
