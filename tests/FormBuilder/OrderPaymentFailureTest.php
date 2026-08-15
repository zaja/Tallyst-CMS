<?php

namespace App\Tests\FormBuilder;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tallyst\FormBuilder\Entity\Order;
use Tallyst\FormBuilder\Payment\WebhookResult;
use Tallyst\FormBuilder\Service\OrderPaymentSync;

/**
 * What happens when a provider says a checkout will not be paid.
 *
 * ⚠ NO MAIL LEAVES FROM HERE, and that is the reason the deadline is a separate step rather than
 * this branch doing everything. A declined card is the most ordinary event in a shop: the buyer is
 * still standing at the till and usually reaches for another card within the minute. Writing to them
 * at that moment would be both wrong and alarming. Only the 24-hour sweep, which knows the checkout
 * was never completed by ANY means, is allowed to write.
 */
class OrderPaymentFailureTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    /** @var int[] */
    private array $orderIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    /** ⚠ Several tests in this suite count whole tables — these rows must not survive. */
    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        foreach ($this->orderIds as $id) {
            $conn->executeStatement('DELETE FROM fb_order WHERE id = ?', [$id]);
        }
        $this->orderIds = [];
        parent::tearDown();
    }

    private function sync(): OrderPaymentSync
    {
        return static::getContainer()->get(OrderPaymentSync::class);
    }

    private function order(string $status = Order::STATUS_PENDING, string $sessionId = 'cs_fail_test'): Order
    {
        $order = (new Order())
            ->setProductName('Arca Backup')
            ->setAmountMinor(3625)
            ->setCurrency('eur')
            ->setStatus($status)
            ->setProviderSessionId($sessionId.'_'.uniqid());
        $this->em->persist($order);
        $this->em->flush();
        $this->orderIds[] = $order->getId();

        return $order;
    }

    private function failure(Order $order, string $eventType = 'checkout.session.expired'): WebhookResult
    {
        return new WebhookResult(
            eventType: $eventType,
            sessionId: $order->getProviderSessionId(),
            paymentIntentId: null,
            isPaid: false,
            customerEmail: null,
            isFailed: true,
            failureReason: 'card_declined',
        );
    }

    /** ⚠ THE ONE THAT MATTERS: a checkout the provider gave up on stops being "awaiting payment". */
    public function testAProviderFailureClosesTheCheckout(): void
    {
        $order = $this->order();

        self::assertSame('OK', $this->sync()->apply($this->failure($order)));

        $this->em->refresh($order);
        self::assertTrue($order->isFailed());
    }

    /** ⚠ And the record of it is stamped automatically, from wherever the closing happened. */
    public function testTheAbandonmentIsStamped(): void
    {
        $order = $this->order();

        $this->sync()->apply($this->failure($order));

        $this->em->refresh($order);
        self::assertTrue($order->wasAbandoned(), 'the owner must be able to see this was walked away from');
    }

    /**
     * ⚠ THE DANGEROUS CASE. A late duplicate of a decline must never reach an order that has since
     * been paid — the provider's LATER word wins, not its earlier one. Without this guard a retried
     * webhook could mark a paid sale as not completed.
     */
    public function testAFailureNeverTouchesAnOrderThatHasSinceBeenPaid(): void
    {
        $order = $this->order(Order::STATUS_PAID);

        self::assertSame('Already resolved', $this->sync()->apply($this->failure($order)));

        $this->em->refresh($order);
        self::assertTrue($order->isPaid());
        self::assertFalse($order->wasAbandoned());
    }

    /** Same for a delivered or refunded sale — nothing walks back out of those. */
    public function testAFailureNeverTouchesADeliveredOrRefundedOrder(): void
    {
        foreach ([Order::STATUS_FULFILLED, Order::STATUS_REFUNDED] as $status) {
            $order = $this->order($status);

            self::assertSame('Already resolved', $this->sync()->apply($this->failure($order)));

            $this->em->refresh($order);
            self::assertSame($status, $order->getStatus());
        }
    }

    /** Repeating the same failure is harmless — providers retry webhooks routinely. */
    public function testRepeatingTheFailureIsHarmless(): void
    {
        $order = $this->order();
        $sync = $this->sync();

        $sync->apply($this->failure($order));
        $this->em->refresh($order);
        $stampedAt = $order->getAbandonedAt();

        self::assertSame('Already resolved', $sync->apply($this->failure($order)));

        $this->em->refresh($order);
        self::assertEquals($stampedAt, $order->getAbandonedAt(), 'the first abandonment time is the one that counts');
    }

    /**
     * ⚠ THE EDGE FROM THE WORKFLOW, END TO END. A slow method settles after the checkout was closed:
     * the provider now says it holds the money, so the money wins — and the abandonment record
     * survives, because that is how the owner sees who left and came back.
     */
    public function testALatePaymentReopensTheOrderButKeepsTheRecord(): void
    {
        $order = $this->order();
        $sync = $this->sync();
        $sync->apply($this->failure($order));

        $sync->apply(new WebhookResult(
            eventType: 'checkout.session.completed',
            sessionId: $order->getProviderSessionId(),
            paymentIntentId: 'pi_late',
            isPaid: true,
            customerEmail: 'buyer@example.com',
        ));

        $this->em->refresh($order);
        self::assertTrue($order->isPaid(), 'the provider says it has the money');
        self::assertTrue($order->wasAbandoned(), 'and the owner still sees it had been abandoned');
    }

    /** A failure for a checkout we have no record of is acknowledged, not an error. */
    public function testAnUnknownCheckoutIsAcknowledged(): void
    {
        $result = new WebhookResult(
            eventType: 'checkout.session.expired',
            sessionId: 'cs_never_seen',
            paymentIntentId: null,
            isPaid: false,
            customerEmail: null,
            isFailed: true,
        );

        self::assertSame('Unknown order', $this->sync()->apply($result));
    }
}
