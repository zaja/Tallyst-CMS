<?php

namespace App\Tests\FormBuilder;

use App\Repository\MemberRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Workflow\WorkflowInterface;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\FormType;
use Tallyst\FormBuilder\Entity\Order;
use Tallyst\FormBuilder\Message\FulfillOrderMessage;
use Tallyst\FormBuilder\Payment\WebhookResult;
use Tallyst\FormBuilder\Repository\DodoPendingLicenseRepository;
use Tallyst\FormBuilder\Repository\OrderRepository;
use Tallyst\FormBuilder\Service\OrderMailer;
use Tallyst\FormBuilder\Service\OrderPaymentSync;

/**
 * Faza 8 K2: the confirmation-mail DISPATCH ordering (the money transitions are untouched — see the paid/
 * refund flow). Proves the dispatch decision the K0 data mandated:
 *  - a MoR order without the licence yet → dispatch with a GRACE delay (fallback for a no-licence product);
 *  - a MoR order with the licence already on it → dispatch immediately;
 *  - a non-MoR order → dispatch immediately (unchanged);
 *  - a licence entitlement landing on a paid, not-yet-notified order → dispatch immediately.
 * Pure unit test: the bus is mocked to capture the stamps.
 */
class OrderPaymentSyncMailDispatchTest extends TestCase
{
    /** @var array<int, object> */
    private array $stamps = [];
    private int $dispatchCount = 0;

    private function sync(Order $order): OrderPaymentSync
    {
        $orders = $this->createStub(OrderRepository::class);
        $orders->method('find')->willReturn($order);
        $orders->method('findOneByProviderPaymentIntentId')->willReturn($order);

        $workflow = $this->createStub(WorkflowInterface::class);
        $workflow->method('can')->willReturn(true);

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $msg, array $stamps = []): Envelope {
            ++$this->dispatchCount;
            $this->stamps = $stamps;

            return new Envelope($msg);
        });

        $pending = $this->createStub(DodoPendingLicenseRepository::class);
        $pending->method('findByPaymentId')->willReturn(null);

        // No account for this buyer's address — the ordinary case, and the one that leaves the
        // order waiting to be adopted at first login rather than bound here.
        $members = $this->createStub(MemberRepository::class);
        $members->method('findByEmail')->willReturn(null);

        return new OrderPaymentSync(
            $orders, $workflow, $bus, $this->createStub(EntityManagerInterface::class),
            $this->createStub(OrderMailer::class), new NullLogger(), $pending, $members,
        );
    }

    private function paidResult(): WebhookResult
    {
        return new WebhookResult('payment.succeeded', null, 'pay_1', true, null, orderId: '1');
    }

    private function hasDelay(): bool
    {
        foreach ($this->stamps as $s) {
            if ($s instanceof DelayStamp) {
                return true;
            }
        }

        return false;
    }

    /** A real MoR order carries the snapshot written at checkout — that flag, not the form, is what is read. */
    private function morOrder(bool $withLicense): Order
    {
        $order = (new Order())
            ->setForm((new FormDefinition())->setFormType(FormType::DIGITAL_MOR)->setMorProvider('dodo'))
            ->setIsMerchantOfRecord(true)
            ->setStatus(Order::STATUS_PENDING);
        if ($withLicense) {
            $order->setLicenseKey('LIC-123');
        }

        return $order;
    }

    public function testMoRWithoutLicenceDispatchesWithGraceDelay(): void
    {
        $this->sync($this->morOrder(false))->apply($this->paidResult());

        self::assertSame(1, $this->dispatchCount);
        self::assertTrue($this->hasDelay(), 'MoR without a licence yet → grace-delayed dispatch');
    }

    public function testMoRWithLicenceDispatchesImmediately(): void
    {
        $this->sync($this->morOrder(true))->apply($this->paidResult());

        self::assertSame(1, $this->dispatchCount);
        self::assertFalse($this->hasDelay(), 'MoR with the licence already on the order → immediate');
    }

    public function testNonMoRDispatchesImmediately(): void
    {
        $order = (new Order())
            ->setForm((new FormDefinition())->setFormType(FormType::DIGITAL))
            ->setStatus(Order::STATUS_PENDING);

        $this->sync($order)->apply($this->paidResult());

        self::assertSame(1, $this->dispatchCount);
        self::assertFalse($this->hasDelay(), 'non-MoR (Stripe/PayPal) → immediate, unchanged');
    }

    /**
     * The snapshot is read ALONE — a `false` on the order wins even when the form now says MoR.
     *
     * This is the case a fallback to the live form would get wrong: the flag is a plain bool, so a
     * fallback makes `false` unable to ever win, and "this was not a MoR purchase" turns back into a
     * question asked of the form. Here a self-billed (Stripe) sale is followed by the shop owner
     * switching that form over to a Merchant-of-Record provider; the already-paid order must still be
     * treated as what it was, and its mail must go out immediately instead of waiting out a licence
     * grace period for a licence that is never coming.
     */
    public function testFalseSnapshotWinsOverAFormLaterSwitchedToMoR(): void
    {
        $form = (new FormDefinition())->setFormType(FormType::DIGITAL);
        $order = (new Order())
            ->setForm($form)
            ->setIsMerchantOfRecord(false) // measured at checkout: this was a self-billed sale
            ->setStatus(Order::STATUS_PENDING);

        // Afterwards the owner converts the product to sell through a Merchant of Record.
        $form->setFormType(FormType::DIGITAL_MOR)->setMorProvider('dodo');

        $this->sync($order)->apply($this->paidResult());

        self::assertSame(1, $this->dispatchCount);
        self::assertFalse(
            $this->hasDelay(),
            'the order was not a MoR purchase, so its mail must go out now — not sit through a grace delay'
        );
    }

    public function testEntitlementOnPaidOrderDispatchesImmediately(): void
    {
        // The licence entitlement lands on an already-paid, not-yet-notified order (K0's majority case).
        $order = $this->morOrder(false)->setStatus(Order::STATUS_PAID);
        $result = new WebhookResult('entitlement_grant.created', null, 'pay_1', false, null, isEntitlement: true, licenseKey: 'LIC-9');

        $this->sync($order)->applyEntitlement($result);

        self::assertSame(1, $this->dispatchCount, 'licence just landed on a paid order → send now');
        self::assertFalse($this->hasDelay(), 'immediate (do not wait out the grace)');
        self::assertSame('LIC-9', $order->getLicenseKey());
    }

    public function testEntitlementDoesNotReDispatchWhenAlreadySent(): void
    {
        $order = $this->morOrder(false)->setStatus(Order::STATUS_PAID)->setConfirmationSentAt(new \DateTimeImmutable());
        $result = new WebhookResult('entitlement_grant.created', null, 'pay_1', false, null, isEntitlement: true, licenseKey: 'LIC-9');

        $this->sync($order)->applyEntitlement($result);

        self::assertSame(0, $this->dispatchCount, 'confirmation already sent → no re-dispatch');
    }
}
