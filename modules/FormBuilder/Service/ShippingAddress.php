<?php

namespace Tallyst\FormBuilder\Service;

/**
 * The canonical delivery-address field set, required when a form offers shipping. The SINGLE source
 * for both the front render (FormShortcode → render.html.twig) and the submit validation
 * (FormSubmitController), so the two can't drift. The values land in FormSubmission.data under these
 * stable keys — NO structured address columns, NO synthetic FormField entities. Presence is DERIVED
 * from "the form offers delivery" (no stored flag) — consistent with "priced ⇐ has price". Country is a
 * plain text field in Faza 1 (shipping-country logic / ISO is Faza 2). See PLAN-FAZA-1-DOSTAVA.md §7.
 */
final class ShippingAddress
{
    /**
     * key => front label translation key (`messages` domain). All required.
     *
     * @var array<string, string>
     */
    public const FIELDS = [
        'ship_name' => 'form.ship.name',
        'ship_address' => 'form.ship.address',
        'ship_city' => 'form.ship.city',
        'ship_postal' => 'form.ship.postal',
        'ship_country' => 'form.ship.country',
    ];
}
