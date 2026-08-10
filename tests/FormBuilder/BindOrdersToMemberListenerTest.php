<?php

namespace App\Tests\FormBuilder;

use App\Member\MemberAuthenticatedEvent;
use App\Entity\Member;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Tallyst\FormBuilder\EventListener\BindOrdersToMemberListener;
use Tallyst\FormBuilder\Entity\Order;
use Tallyst\FormBuilder\Repository\OrderRepository;

/**
 * When a buyer proves their address, the sales already sitting under that address become theirs.
 * This runs on EVERY login, not only the first, so an order that missed its moment is picked up
 * the next time the customer comes back rather than staying orphaned for good.
 */
class BindOrdersToMemberListenerTest extends TestCase
{
    /** @param list<Order> $unbound */
    private function listener(array $unbound): BindOrdersToMemberListener
    {
        $orders = $this->createStub(OrderRepository::class);
        $orders->method('findUnboundByEmail')->willReturn($unbound);

        return new BindOrdersToMemberListener($orders, $this->createStub(EntityManagerInterface::class));
    }

    private function order(): Order
    {
        return (new Order())->setCustomerEmail('pero@example.com');
    }

    public function testWaitingOrdersBecomeTheCustomersOnFirstLogin(): void
    {
        $a = $this->order();
        $b = $this->order();
        $member = new Member('pero@example.com');

        $this->listener([$a, $b])(new MemberAuthenticatedEvent($member, accountWasJustCreated: true));

        self::assertSame($member, $a->getMember());
        self::assertSame($member, $b->getMember());
    }

    /**
     * ⚠ The self-healing case. An order paid while the account already existed, but never attached —
     * a blocked webhook, or a sale that predates this feature — must be claimed on a LATER login too.
     */
    public function testOrdersAreAlsoClaimedOnAReturningLoginNotJustAccountCreation(): void
    {
        $stray = $this->order();

        $this->listener([$stray])(new MemberAuthenticatedEvent(new Member('pero@example.com'), accountWasJustCreated: false));

        self::assertNotNull($stray->getMember(), 'a returning login must still adopt stray orders');
    }

    public function testNothingToClaimIsHarmless(): void
    {
        $this->listener([])(new MemberAuthenticatedEvent(new Member('pero@example.com'), accountWasJustCreated: true));

        $this->expectNotToPerformAssertions();
    }
}
