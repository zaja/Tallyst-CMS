<?php

namespace Tallyst\FormBuilder\Twig;

use Tallyst\FormBuilder\Service\ShippingCatalog;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the shipping-method catalog to the Postavke → Dostava editor partial, so the Core settings
 * template can render the (FormBuilder-owned) catalog without a Core→FormBuilder PHP dependency — same
 * loose-Twig pattern as PaymentAdminExtension's payment_provider_names().
 */
class ShippingAdminExtension extends AbstractExtension
{
    public function __construct(private readonly ShippingCatalog $catalog)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('shipping_methods', fn (): array => $this->catalog->all()),
        ];
    }
}
