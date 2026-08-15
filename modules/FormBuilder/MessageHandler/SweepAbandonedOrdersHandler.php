<?php

namespace Tallyst\FormBuilder\MessageHandler;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Tallyst\FormBuilder\Message\SweepAbandonedOrdersMessage;
use Tallyst\FormBuilder\Service\AbandonedOrderSweeper;

/**
 * ⚠ Idempotent by construction: the sweep only ever acts on checkouts still waiting past the
 * deadline, so running it twice in a row does nothing the second time. That matters because the
 * scheduler will re-deliver after a worker restart.
 */
#[AsMessageHandler]
final readonly class SweepAbandonedOrdersHandler
{
    public function __construct(private AbandonedOrderSweeper $sweeper)
    {
    }

    public function __invoke(SweepAbandonedOrdersMessage $message): void
    {
        $this->sweeper->sweep();
    }
}
