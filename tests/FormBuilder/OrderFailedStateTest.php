<?php

namespace App\Tests\FormBuilder;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Workflow\WorkflowInterface;
use Tallyst\FormBuilder\Entity\Order;

/**
 * The shape of the "never completed" state, and the one edge that looks like a contradiction.
 *
 * ⚠ WHY THE STATE EXISTS. `pending` used to mean two different things — "the payment is going
 * through, wait" and "this never happened" — with nothing to tell them apart and nothing ever
 * closing the second. Measured before this work: a third of all orders on the dev database were
 * pending, none younger than a month, each one still being promised a confirmation e-mail that was
 * never going to arrive.
 */
class OrderFailedStateTest extends KernelTestCase
{
    private function workflow(): WorkflowInterface
    {
        self::bootKernel();

        return static::getContainer()->get('state_machine.order');
    }

    private function order(string $status): Order
    {
        return (new Order())->setStatus($status);
    }

    public function testAPendingOrderCanBeDeclaredNotCompleted(): void
    {
        self::assertTrue($this->workflow()->can($this->order(Order::STATUS_PENDING), 'fail'));
    }

    /**
     * ⚠ THE EDGE THAT LOOKS LIKE A CONTRADICTION AND IS NOT. Status changes only on what the
     * PROVIDER asserts, never on what the buyer clicks. A bank transfer or SEPA payment can settle
     * after the deadline has already closed the order — the provider now says it holds the money, so
     * the money wins the deadline. It is the same purchase, confirmed late.
     *
     * Refusing this would leave the books reading "not completed" for funds sitting in the account.
     */
    public function testAProviderCanStillConfirmPaymentAfterTheDeadlineClosedIt(): void
    {
        self::assertTrue(
            $this->workflow()->can($this->order(Order::STATUS_FAILED), 'pay'),
            'a verified webhook must outrank the deadline',
        );
    }

    /** ⚠ And it is a one-way door in every other direction: nothing walks back out of paid or refunded. */
    public function testACompletedOrderCanNeverBeDeclaredNotCompleted(): void
    {
        $workflow = $this->workflow();

        self::assertFalse($workflow->can($this->order(Order::STATUS_PAID), 'fail'));
        self::assertFalse($workflow->can($this->order(Order::STATUS_FULFILLED), 'fail'));
        self::assertFalse($workflow->can($this->order(Order::STATUS_REFUNDED), 'fail'));
    }

    /** A not-completed order was never paid, so there is nothing to refund or deliver. */
    public function testThereIsNothingToDeliverOrRefundOnANotCompletedOrder(): void
    {
        $workflow = $this->workflow();
        $failed = $this->order(Order::STATUS_FAILED);

        self::assertFalse($workflow->can($failed, 'fulfill'));
        self::assertFalse($workflow->can($failed, 'refund'));
    }

    /**
     * ⚠ THE RECORD OUTLIVES THE STATE. When a late payment turns a closed order back into a paid
     * one, the owner must still be able to see it had been walked away from — that is how they
     * learn how many people drop out and how many come back. Clearing this on payment would erase
     * exactly the number the state was introduced to expose.
     */
    public function testTheAbandonmentRecordSurvivesALatePayment(): void
    {
        $abandonedAt = new \DateTimeImmutable('2026-08-14 10:00:00');
        $order = $this->order(Order::STATUS_FAILED)->setAbandonedAt($abandonedAt);

        // The late webhook arrives and money wins.
        $this->workflow()->apply($order, 'pay');

        self::assertTrue($order->isPaid());
        self::assertTrue($order->wasAbandoned(), 'the owner must still see this was abandoned');
        self::assertSame($abandonedAt, $order->getAbandonedAt());
    }

    /** A normal purchase carries no such record. */
    public function testAnOrdinaryOrderCarriesNoAbandonmentRecord(): void
    {
        self::assertFalse($this->order(Order::STATUS_PAID)->wasAbandoned());
    }

    /** ⚠ Not-completed money is not revenue: isPaid() gates the dashboard totals. */
    public function testANotCompletedOrderIsNotCountedAsPaid(): void
    {
        self::assertFalse($this->order(Order::STATUS_FAILED)->isPaid());
        self::assertTrue($this->order(Order::STATUS_FAILED)->isFailed());
    }
}
