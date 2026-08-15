<?php

namespace App\Tests\FormBuilder;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tallyst\FormBuilder\Entity\Order;
use Tallyst\FormBuilder\Service\AbandonedOrderSweeper;

/**
 * Who is told their checkout was never completed — and, far more importantly, who is NOT.
 *
 * ⚠ THE DANGEROUS DIRECTION IS SENDING, NOT WITHHOLDING. An unsent message costs a little; a wrongly
 * sent one costs the owner's standing with their own customers, and cannot be recalled. The first
 * sweep after an upgrade is the moment that could go wrong at scale: without the activation stamp it
 * would write to everybody who ever abandoned a basket, including people who walked away months
 * before the shop was capable of noticing.
 */
class AbandonedOrderMailTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    /** @var int[] */
    private array $orderIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement('DELETE FROM messenger_messages');
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        foreach ($this->orderIds as $id) {
            $conn->executeStatement('DELETE FROM fb_order WHERE id = ?', [$id]);
        }
        $conn->executeStatement('DELETE FROM messenger_messages');
        $conn->executeStatement("DELETE FROM setting WHERE name = 'order_sweep_last_run'");
        $this->orderIds = [];
        parent::tearDown();
    }

    private function sweeper(): AbandonedOrderSweeper
    {
        return static::getContainer()->get(AbandonedOrderSweeper::class);
    }

    /** A pending checkout created at a chosen moment, with a buyer we could write to. */
    private function order(string $createdAt, ?string $email = 'buyer@example.com'): Order
    {
        $order = (new Order())
            ->setProductName('Arca Backup')
            ->setAmountMinor(3625)
            ->setCurrency('eur')
            ->setStatus(Order::STATUS_PENDING)
            ->setThankYouToken(bin2hex(random_bytes(16)))
            ->setReturnPath('/buy-arca');
        if (null !== $email) {
            $order->setCustomerEmail($email);
        }
        $this->em->persist($order);
        $this->em->flush();
        $this->em->getConnection()->executeStatement(
            'UPDATE fb_order SET created_at = ? WHERE id = ?',
            [(new \DateTimeImmutable($createdAt))->format('Y-m-d H:i:s'), $order->getId()],
        );
        $this->em->refresh($order);
        $this->orderIds[] = $order->getId();

        return $order;
    }

    private function queuedMessages(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM messenger_messages');
    }

    private function activatedAt(): \DateTimeImmutable
    {
        return $this->sweeper()->activatedAt() ?? new \DateTimeImmutable('2000-01-01');
    }

    /**
     * ⚠ The sweep is run at an EXPLICIT moment, three days after this site started watching. Real
     * time will not do: the activation stamp was written when the feature was installed, which on a
     * freshly-migrated database is more recent than the 24-hour deadline — so "after activation" and
     * "past the deadline" have no overlap yet, and every case here would silently test nothing.
     */
    private function sweepAt(): array
    {
        return $this->sweeper()->sweep($this->activatedAt()->modify('+3 days'));
    }

    /** ⚠ THE ONE THAT PROTECTS THE OWNER: nothing is sent about a checkout from before the feature. */
    public function testNobodyIsWrittenToAboutACheckoutFromBeforeTheFeatureExisted(): void
    {
        // A month before this site started watching — and long past the deadline, so it IS closed.
        $this->order($this->activatedAt()->modify('-30 days')->format('Y-m-d H:i:s'));

        $result = $this->sweepAt();

        self::assertGreaterThanOrEqual(1, $result['closed'], 'it is still closed');
        self::assertSame(0, $result['notifiable'], 'but silently');
        self::assertSame(0, $this->queuedMessages(), 'no message may leave the building');
    }

    /** A checkout placed after the site started watching, past the deadline, does get the offer. */
    public function testABuyerWhoAbandonedAfterActivationIsOfferedTheWayBack(): void
    {
        $this->order($this->activatedAt()->modify('+1 minute')->format('Y-m-d H:i:s'));

        $result = $this->sweepAt();

        self::assertSame(1, $result['notifiable']);
        self::assertSame(1, $this->queuedMessages(), 'exactly one message, to one buyer');
    }

    /** ⚠ No address was ever captured — there is nobody to write to, and that is not an error. */
    public function testACheckoutWithNoBuyerAddressIsClosedWithoutAMessage(): void
    {
        $this->order($this->activatedAt()->modify('+1 minute')->format('Y-m-d H:i:s'), null);

        $result = $this->sweepAt();

        self::assertGreaterThanOrEqual(1, $result['closed']);
        self::assertSame(0, $this->queuedMessages());
    }

    /**
     * ⚠ Running the sweep again must not write to the same buyer twice. It cannot, by construction —
     * the order is no longer waiting — but providers and schedulers retry, so this is pinned.
     */
    public function testASecondSweepWritesToNobodyAgain(): void
    {
        $this->order($this->activatedAt()->modify('+1 minute')->format('Y-m-d H:i:s'));
        $this->sweepAt();
        $after = $this->queuedMessages();
        $this->sweepAt();

        self::assertSame($after, $this->queuedMessages(), 'nothing new was queued');
    }
}
