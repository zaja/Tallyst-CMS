<?php

namespace Tallyst\FormBuilder\Twig;

use Tallyst\FormBuilder\Service\TaxCatalog;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the tax-rate catalog to the Postavke → Porez editor partial, so the Core settings template can
 * render the (FormBuilder-owned) catalog without a Core→FormBuilder PHP dependency — same loose-Twig
 * pattern as ShippingAdminExtension's shipping_methods().
 */
class TaxAdminExtension extends AbstractExtension
{
    public function __construct(private readonly TaxCatalog $catalog)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('tax_rates', fn (): array => $this->catalog->all()),
        ];
    }
}
