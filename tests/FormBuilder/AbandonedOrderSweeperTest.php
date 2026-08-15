<?php

namespace App\Tests\FormBuilder;

use App\Settings\SettingsManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tallyst\FormBuilder\Entity\Order;
use Tallyst\FormBuilder\Service\AbandonedOrderSweeper;

/**
 * The 24-hour deadline: the floor under everything the providers do not tell us.
 *
 * ⚠ IT EXISTS BECAUSE MOST ABANDONED CHECKOUTS PRODUCE NO EVENT AT ALL. Somebody closes the window
 * and tells nobody; Stripe reports nothing for a card declined inside Checkout; and the provider
 * events that do exist only arrive once the owner has subscribed them in their dashboard, which an
 * upgrade cannot do for them. This is the part that works everywhere with no configuration.
 */
class AbandonedOrderSweeperTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    /** @var int[] */
    private array $orderIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    /** ⚠ Other tests count whole tables — nothing created here may survive. */
    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        foreach ($this->orderIds as $id) {
            $conn->executeStatement('DELETE FROM fb_order WHERE id = ?', [$id]);
        }
        $conn->executeStatement("DELETE FROM setting WHERE name IN ('order_sweep_last_run')");
        $this->orderIds = [];
        parent::tearDown();
    }

    private function sweeper(): AbandonedOrderSweeper
    {
        return static::getContainer()->get(AbandonedOrderSweeper::class);
    }

    private function order(string $createdAt, string $status = Order::STATUS_PENDING): Order
    {
        $order = (new Order())
            ->setProductName('Arca Backup')
            ->setAmountMinor(3625)
            ->setCurrency('eur')
            ->setStatus($status);
        $this->em->persist($order);
        $this->em->flush();

        // createdAt is set by a lifecycle callback, so it is rewritten directly afterwards.
        $this->em->getConnection()->executeStatement(
            'UPDATE fb_order SET created_at = ? WHERE id = ?',
            [(new \DateTimeImmutable($createdAt))->format('Y-m-d H:i:s'), $order->getId()],
        );
        $this->em->refresh($order);
        $this->orderIds[] = $order->getId();

        return $order;
    }

    /** ⚠ THE ONE THAT MATTERS: a checkout nobody finished stops waiting for ever. */
    public function testACheckoutPastTheDeadlineIsClosed(): void
    {
        $old = $this->order('-3 days');

        $this->sweeper()->sweep();

        $this->em->refresh($old);
        self::assertTrue($old->isFailed());
        self::assertTrue($old->wasAbandoned(), 'and the owner can see it was walked away from');
    }

    /**
     * ⚠ 24 HOURS IS NOT A GUESS AT IMPATIENCE. A bank transfer or SEPA debit legitimately takes most
     * of a day, so a checkout younger than the deadline must be left completely alone.
     */
    public function testACheckoutInsideTheDeadlineIsLeftAlone(): void
    {
        $recent = $this->order('-2 hours');

        $this->sweeper()->sweep();

        $this->em->refresh($recent);
        self::assertSame(Order::STATUS_PENDING, $recent->getStatus());
        self::assertFalse($recent->wasAbandoned());
    }

    /** The deadline decides nothing the money has already decided. */
    public function testPaidAndRefundedOrdersAreNeverTouchedHoweverOld(): void
    {
        $paid = $this->order('-90 days', Order::STATUS_PAID);
        $refunded = $this->order('-90 days', Order::STATUS_REFUNDED);

        $this->sweeper()->sweep();

        $this->em->refresh($paid);
        $this->em->refresh($refunded);
        self::assertSame(Order::STATUS_PAID, $paid->getStatus());
        self::assertSame(Order::STATUS_REFUNDED, $refunded->getStatus());
    }

    /** Running it twice does nothing the second time — the scheduler re-delivers after a restart. */
    public function testRunningItTwiceIsHarmless(): void
    {
        $this->order('-3 days');
        $sweeper = $this->sweeper();

        $first = $sweeper->sweep();
        $second = $sweeper->sweep();

        self::assertGreaterThanOrEqual(1, $first['closed']);
        self::assertSame(0, $second['closed'], 'nothing is left to close');
    }

    // ── The silence rule ────────────────────────────────────────────────────

    /**
     * ⚠ THE RULE THAT PROTECTS THE OWNER'S REPUTATION. A checkout abandoned before this site started
     * watching is closed WITHOUT a word, whatever its age. Otherwise the first sweep after an upgrade
     * writes to every customer who ever changed their mind — people who walked away months before
     * the shop was capable of noticing.
     *
     * ⚠ A time window ("younger than 48 hours") does NOT achieve this, because an upgrade can land at
     * any point relative to those orders. Only the activation stamp does.
     */
    public function testAnOrderFromBeforeTheFeatureExistedIsClosedInSilence(): void
    {
        $sweeper = $this->sweeper();
        $activatedAt = new \DateTimeImmutable('2026-08-14 20:56:00');

        self::assertFalse(
            $sweeper->mayNotify(new \DateTimeImmutable('2026-07-13 00:44:00'), $activatedAt),
            'abandoned a month before the shop could notice — say nothing',
        );
    }

    public function testAnOrderPlacedAfterActivationMayBeNotified(): void
    {
        $activatedAt = new \DateTimeImmutable('2026-08-14 20:56:00');

        self::assertTrue(
            $this->sweeper()->mayNotify(new \DateTimeImmutable('2026-08-15 09:00:00'), $activatedAt),
        );
    }

    /** ⚠ No stamp means we cannot prove the order came after the feature — so we stay quiet. */
    public function testWithoutAnActivationStampNobodyIsNotified(): void
    {
        self::assertFalse(
            $this->sweeper()->mayNotify(new \DateTimeImmutable('now'), null),
            'erring towards silence is the only safe direction',
        );
    }

    /** The run is recorded, which is what lets readiness tell "nothing to do" from "nothing running". */
    public function testTheRunIsRecorded(): void
    {
        $sweeper = $this->sweeper();
        $sweeper->sweep(new \DateTimeImmutable('2026-08-15 10:00:00'));

        self::assertSame(
            '2026-08-15 10:00',
            $sweeper->lastRunAt()?->format('Y-m-d H:i'),
        );
        // And it is persisted, not just held in memory.
        self::assertNotNull(static::getContainer()->get(SettingsManager::class)->get(AbandonedOrderSweeper::LAST_RUN_SETTING));
    }
}
