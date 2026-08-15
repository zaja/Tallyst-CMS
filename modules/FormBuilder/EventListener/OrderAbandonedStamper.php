<?php

namespace Tallyst\FormBuilder\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\EnteredEvent;
use Tallyst\FormBuilder\Entity\Order;

/**
 * Stamps `abandoned_at` the moment an order is declared not completed — from wherever that happens.
 *
 * ⚠ IT IS A LISTENER RATHER THAN TWO LINES AT EACH CALL SITE ON PURPOSE. Two things can close a
 * checkout — a provider's own failure event and the 24-hour deadline sweep — and more may follow.
 * The timestamp is not decoration: it is the ONLY evidence the owner keeps that a purchase was
 * walked away from, and it has to survive a later payment, so a call site that forgot to write it
 * would silently erase that history for every order it touched. Hanging it on the transition itself
 * makes forgetting impossible.
 *
 * ⚠ It never overwrites an existing stamp. An order closed, paid late, and somehow closed again must
 * keep pointing at the FIRST time it was abandoned; that is the moment the owner is measuring.
 *
 * Flushing is left to the caller, which is already flushing its own work in the same transaction.
 */
final class OrderAbandonedStamper
{
    #[AsEventListener(event: 'workflow.order.entered.failed')]
    public function __invoke(EnteredEvent $event): void
    {
        $order = $event->getSubject();
        if (!$order instanceof Order || $order->wasAbandoned()) {
            return;
        }

        $order->setAbandonedAt(new \DateTimeImmutable());
    }
}
