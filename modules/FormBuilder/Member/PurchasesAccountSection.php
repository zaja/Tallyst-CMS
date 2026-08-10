<?php

namespace Tallyst\FormBuilder\Member;

use App\Member\MemberAccountSectionInterface;
use App\Entity\Member;
use Tallyst\FormBuilder\Repository\OrderRepository;

/**
 * The "your purchases" block on a member's account page.
 *
 * Lives in FormBuilder because FormBuilder owns orders; Core renders whatever blocks are tagged
 * and never learns what an Order is.
 *
 * ⚠ Shows EVERY order this account owns, whatever its state — including ones that never completed.
 * A buyer who is unsure whether a payment went through is exactly the person opening this page, and
 * hiding an unfinished attempt would leave them with no answer at all. The state wording is
 * deliberately calm rather than alarming: a provider's confirmation can legitimately lag.
 */
final readonly class PurchasesAccountSection implements MemberAccountSectionInterface
{
    public function __construct(private OrderRepository $orders)
    {
    }

    public function getPosition(): int
    {
        return 10;
    }

    public function getTemplate(): string
    {
        return '@FormBuilder/member/_purchases.html.twig';
    }

    public function getData(Member $member): array
    {
        return ['orders' => $this->orders->findForMember($member)];
    }
}
