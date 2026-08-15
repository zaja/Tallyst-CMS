<?php

namespace App\Member;

/**
 * The thing a member is looking at when they ask for help.
 *
 * ⚠ DELIBERATELY GENERIC, and that is the entire point. Core must not learn what an order is —
 * modules depend on Core, never the reverse — so a page that wants a help affordance describes its
 * subject in three neutral parts and Core stays ignorant of what they mean. FormBuilder passes
 * `('order', 42, 'Arca Backup')`; a subscription or a booking would pass its own type and Core needs
 * no change to carry it.
 *
 * This is what lets a support module later open a request ALREADY LINKED to the purchase: the type
 * and id travel with the request, so the module can resolve them itself without Core ever having
 * described the relationship.
 */
final readonly class MemberHelpSubject
{
    public function __construct(
        /** A module's own name for what this is — `order`, later `subscription`, `booking`. */
        public string $type,
        /** The identifier within that type, as the owning module understands it. */
        public string|int $id,
        /** What to call it in front of a person: a product name, not a reference. */
        public string $label,
    ) {
    }
}
