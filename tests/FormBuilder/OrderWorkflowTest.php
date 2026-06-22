<?php

namespace App\Tests\FormBuilder;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Workflow\WorkflowInterface;
use Tallyst\FormBuilder\Entity\Order;

/**
 * Locks the manual-fulfilment transition: `fulfill` (paid → fulfilled) is allowed only from `paid`,
 * so the admin action can advance a paid order but not a pending one.
 */
class OrderWorkflowTest extends KernelTestCase
{
    public function testFulfillAllowedFromPaidOnly(): void
    {
        self::bootKernel();
        /** @var WorkflowInterface $workflow */
        $workflow = self::getContainer()->get('state_machine.order');

        $paid = (new Order())->setStatus(Order::STATUS_PAID);
        self::assertTrue($workflow->can($paid, 'fulfill'), 'paid → fulfilled must be allowed');

        $pending = (new Order())->setStatus(Order::STATUS_PENDING);
        self::assertFalse($workflow->can($pending, 'fulfill'), 'pending → fulfilled must NOT be allowed');
    }

    public function testRefundAllowedFromPaidAndFulfilledOnly(): void
    {
        self::bootKernel();
        /** @var WorkflowInterface $workflow */
        $workflow = self::getContainer()->get('state_machine.order');

        $paid = (new Order())->setStatus(Order::STATUS_PAID);
        self::assertTrue($workflow->can($paid, 'refund'), 'paid → refunded must be allowed');

        $fulfilled = (new Order())->setStatus(Order::STATUS_FULFILLED);
        self::assertTrue($workflow->can($fulfilled, 'refund'), 'fulfilled → refunded must be allowed');

        $pending = (new Order())->setStatus(Order::STATUS_PENDING);
        self::assertFalse($workflow->can($pending, 'refund'), 'pending → refunded must NOT be allowed');
    }
}
