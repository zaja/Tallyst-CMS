<?php

namespace Tallyst\FormBuilder\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Tallyst\FormBuilder\Entity\Order;
use Tallyst\FormBuilder\Message\FulfillOrderMessage;
use Tallyst\FormBuilder\Repository\OrderRepository;
use Tallyst\FormBuilder\Service\OrderMailer;

/**
 * On payment: send the customer confirmation + the admin notice (async, retriable). Runs once
 * per payment — the webhook applies `pay` only when not already paid and dispatches this message
 * exactly once, so duplicate webhook deliveries don't double-send.
 *
 * MANUAL FULFILMENT (Option B): this does NOT advance the order to `fulfilled`. The order stays
 * `paid` ("awaiting delivery") until an admin marks it delivered (OrderCrudController action), so
 * `fulfilled` means "admin delivered", not "confirmation emailed".
 */
#[AsMessageHandler]
class FulfillOrderHandler
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly OrderMailer $mailer,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(FulfillOrderMessage $message): void
    {
        $order = $this->orders->find($message->orderId);
        if (null === $order) {
            return;
        }

        // Only act on a paid order (defensive — the webhook only dispatches on the pay transition).
        if (Order::STATUS_PAID !== $order->getStatus()) {
            return;
        }

        // Faza 8 K2: send EXACTLY ONCE. A MoR order can be dispatched twice on purpose (the grace-delayed
        // paid dispatch + the immediate entitlement dispatch); whichever the worker processes first sends,
        // the other sees confirmationSentAt set and no-ops. The flag is set AFTER the send (which only
        // ENQUEUES the async mail) so a rare enqueue failure retries instead of silently losing the mail.
        if (null !== $order->getConfirmationSentAt()) {
            return;
        }

        $this->mailer->sendConfirmation($order);
        $this->mailer->sendAdminNotice($order);

        $order->setConfirmationSentAt(new \DateTimeImmutable());
        $this->em->flush();
    }
}
