<?php

namespace Tallyst\FormBuilder\Message;

/**
 * Dispatched once an order is marked paid. Fulfillment (confirmation e-mails, then
 * the paid→fulfilled transition) runs asynchronously and is retriable — so a slow
 * or failing mailer never delays the webhook nor rolls back the paid state.
 */
final class FulfillOrderMessage
{
    public function __construct(
        public readonly int $orderId,
    ) {
    }
}
