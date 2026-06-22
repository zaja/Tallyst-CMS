<?php

namespace App\Tests\FormBuilder;

use PHPUnit\Framework\TestCase;
use Tallyst\FormBuilder\Entity\Order;
use Tallyst\FormBuilder\Message\FulfillOrderMessage;
use Tallyst\FormBuilder\MessageHandler\FulfillOrderHandler;
use Tallyst\FormBuilder\Repository\OrderRepository;
use Tallyst\FormBuilder\Service\OrderMailer;

/**
 * Manual-fulfilment (Option B): on a paid order the handler sends the confirmation + admin notice
 * but does NOT advance to `fulfilled` — the order stays `paid` (awaiting the admin's manual
 * delivery). A non-paid order is a no-op (defensive guard).
 */
class FulfillOrderHandlerTest extends TestCase
{
    public function testSendsMailsAndKeepsOrderPaid(): void
    {
        $order = (new Order())->setStatus(Order::STATUS_PAID);

        $orders = $this->createStub(OrderRepository::class);
        $orders->method('find')->willReturn($order);

        $mailer = $this->createMock(OrderMailer::class);
        $mailer->expects(self::once())->method('sendConfirmation')->with($order);
        $mailer->expects(self::once())->method('sendAdminNotice')->with($order);

        (new FulfillOrderHandler($orders, $mailer))(new FulfillOrderMessage(1));

        self::assertSame(Order::STATUS_PAID, $order->getStatus(), 'handler must NOT auto-fulfil');
    }

    public function testNoOpWhenNotPaid(): void
    {
        $order = (new Order())->setStatus(Order::STATUS_PENDING);

        $orders = $this->createStub(OrderRepository::class);
        $orders->method('find')->willReturn($order);

        $mailer = $this->createMock(OrderMailer::class);
        $mailer->expects(self::never())->method('sendConfirmation');
        $mailer->expects(self::never())->method('sendAdminNotice');

        (new FulfillOrderHandler($orders, $mailer))(new FulfillOrderMessage(1));
    }
}
