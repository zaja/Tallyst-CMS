<?php

namespace App\Tests\FormBuilder;

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

        return new OrderPaymentSync(
            $orders, $workflow, $bus, $this->createStub(EntityManagerInterface::class),
            $this->createStub(OrderMailer::class), new NullLogger(), $pending,
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

    private function morOrder(bool $withLicense): Order
    {
        $order = (new Order())
            ->setForm((new FormDefinition())->setFormType(FormType::DIGITAL_MOR)->setMorProvider('dodo'))
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
