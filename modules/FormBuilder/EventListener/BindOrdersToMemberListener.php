<?php

namespace Tallyst\FormBuilder\EventListener;

use App\Member\MemberAccountViewedEvent;
use App\Member\MemberAuthenticatedEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Tallyst\FormBuilder\Repository\OrderRepository;

/**
 * Attaches the sales sitting under an address to the account that has just proven it.
 *
 * ⚠ THIS IS THE DEPENDENCY BOUNDARY IN ACTION. Core owns the member account and the login flow
 * and knows nothing about orders; the module that owns orders listens for the account event and
 * does the attaching. Support and subscriptions will hook into the same event rather than teaching
 * Core about their tables.
 *
 * ⚠ It runs on EVERY confirmed login, not only the one that creates the account, and that is what
 * makes attachment self-healing. An order paid before the account existed, one whose webhook was
 * blocked when it mattered, or one placed before this feature shipped, is claimed the next time the
 * buyer logs in — instead of staying orphaned because the single moment that could have claimed it
 * has passed.
 *
 * ⚠ IT ALSO RUNS WHEN THE MEMBER SIMPLY OPENS THEIR ACCOUNT, and that is not redundant. A sign-in
 * lasts 90 days, so a member who was already signed in while buying would otherwise not see that
 * purchase until their next sign-in — up to three months later. The account page is exactly where
 * somebody goes to ask "where is my purchase?", so it is where the answer has to be assembled.
 *
 * ⚠ Neither event is proof of anything NEW: both concern an address the member has already proven.
 * Attaching by an address a visitor merely typed into a form is refused on purpose — it would let
 * anyone put a purchase they invented, with their own details, into somebody else's account.
 */
#[AsEventListener(event: MemberAuthenticatedEvent::class)]
#[AsEventListener(event: MemberAccountViewedEvent::class)]
final readonly class BindOrdersToMemberListener
{
    public function __construct(
        private OrderRepository $orders,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(MemberAuthenticatedEvent|MemberAccountViewedEvent $event): void
    {
        $waiting = $this->orders->findUnboundByEmail($event->member->getEmail());
        if ([] === $waiting) {
            return;
        }

        foreach ($waiting as $order) {
            $order->setMember($event->member);
        }

        $this->em->flush();
    }
}
