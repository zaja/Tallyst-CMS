<?php

namespace App\Member;

use App\Entity\Member;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Offers a member a way to get help with something they are looking at.
 *
 * ⚠ THIS IS A PLACE HELD OPEN, NOT A FEATURE. There is no support module yet, so Core answers with
 * a sentence pointing at whatever contact page the site owner has configured. When a support module
 * lands it contributes its own provider at a lower position and takes the place over with a button
 * that opens a request already linked to the purchase — and nothing on the page it appears on has
 * to change. Building it as a seam now costs one interface; retrofitting it later would mean
 * touching every page that had hardcoded a sentence.
 *
 * Same idiom as MemberAccountSectionInterface, dashboard widgets, settings sections and mail types:
 * whoever owns the capability contributes it, and Core renders what it is given.
 *
 * ⚠ EMPTY DATA MEANS "I HAVE NOTHING TO OFFER" and the block is not rendered at all. The default
 * provider returns empty when no contact page is configured, because a "need help?" line with
 * nowhere to go is worse than no line.
 */
#[AutoconfigureTag('app.member_help')]
interface MemberHelpProviderInterface
{
    /**
     * Lower wins. Core's fallback sits at 100 so anything real outranks it without having to know
     * what number the fallback picked.
     */
    public function getPosition(): int;

    public function getTemplate(): string;

    /**
     * @return array<string, mixed> empty = do not render me
     */
    public function getData(Member $member, MemberHelpSubject $subject): array;
}
