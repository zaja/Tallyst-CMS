<?php

namespace App\Member;

use App\Entity\Member;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Contributes one block to a member's account page.
 *
 * ⚠ This exists so Core can own the account page without knowing what is ON it. Orders live in
 * FormBuilder, and Core must not read them (modules depend on Core, never the reverse — Media is
 * the single exception). FormBuilder contributes its purchases block through this tag, the same
 * way it contributes a dashboard widget, a settings section and its own mail types.
 *
 * It is also the seam the roadmap needs: support tickets and subscriptions become another block
 * each, contributed by whatever owns them, without Core learning about their tables.
 */
#[AutoconfigureTag('app.member_account_section')]
interface MemberAccountSectionInterface
{
    /** Lower sorts first. Purchases are 10; later blocks slot around that. */
    public function getPosition(): int;

    public function getTemplate(): string;

    /**
     * Data for the template, for THIS member only.
     *
     * ⚠ EMPTY DATA MEANS "DO NOT RENDER ME". A block with nothing to show is left out of the page
     * entirely, rather than rendering its own "nothing here yet" line. Most new members have bought
     * nothing, so several such lines would be the whole account page.
     *
     * @return array<string, mixed>
     */
    public function getData(Member $member): array;
}
