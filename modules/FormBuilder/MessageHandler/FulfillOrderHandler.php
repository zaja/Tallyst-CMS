<?php

namespace Tallyst\FormBuilder\MessageHandler;

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

        $this->mailer->sendConfirmation($order);
        $this->mailer->sendAdminNotice($order);
    }
}
