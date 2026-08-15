<?php

namespace App\Member;

use App\Entity\Member;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched when a member opens their account page.
 *
 * ⚠ WHY THIS EXISTS ALONGSIDE MemberAuthenticatedEvent. Sales are attached to an account when
 * somebody proves their address, and a sign-in lasts 90 DAYS — so a member who was already signed in
 * when they made a purchase would not see it until their next sign-in, potentially three months
 * later. Measured on 2026-08-15: an abandoned checkout carrying the member's own address sat
 * unattached while the admin could see it perfectly.
 *
 * ⚠ It carries NO new proof of identity and must never be treated as any. It fires for a member who
 * is ALREADY signed in, which means the address was proven earlier; listeners may only act on that
 * same proven address, exactly as they do on the authentication event. Nothing here may act on an
 * address a visitor merely typed somewhere.
 *
 * Same seam as the authentication event: Core owns the account page and stays ignorant of orders,
 * subscriptions and support tickets; whoever owns those listens.
 */
final class MemberAccountViewedEvent extends Event
{
    public function __construct(public readonly Member $member)
    {
    }
}
