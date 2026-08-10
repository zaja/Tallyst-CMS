<?php

namespace App\Member;

use App\Entity\Member;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched every time a member proves their address by confirming a login link — on the first
 * confirmation, which creates the account, and on every one after it.
 *
 * ⚠ This is the seam that keeps Core ignorant of orders. Core owns the account and the login flow;
 * FormBuilder listens here and attaches whatever sales belong to that address. Support and
 * subscriptions will hang off the same event rather than teaching Core about their tables.
 *
 * ⚠ It fires on EVERY confirmation, not only on account creation, and that is deliberate: it makes
 * attaching sales self-healing. An order paid before the account existed, one whose webhook was
 * blocked at the time, or one that predates this feature entirely, all get picked up the next time
 * the member signs in — instead of staying orphaned forever because the one moment that could have
 * claimed them has passed.
 */
final class MemberAuthenticatedEvent extends Event
{
    public function __construct(
        public readonly Member $member,
        public readonly bool $accountWasJustCreated,
    ) {
    }
}
