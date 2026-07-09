<?php

namespace Tallyst\FormBuilder\Twig;

use Tallyst\FormBuilder\Payment\PaymentProcessorRegistry;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes per-provider admin info (mode badge + required webhook events) to the Postavke info
 * partial, for ANY provider. Twig-level (runtime) so the Core settings template uses it without a
 * Core→FormBuilder PHP dependency — same pattern as Media's render_branding/media_img.
 */
class PaymentAdminExtension extends AbstractExtension
{
    public function __construct(private readonly PaymentProcessorRegistry $payments)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('payment_mode', fn (string $provider): string => $this->payments->get($provider)->getMode()),
            new TwigFunction('payment_webhook_events', fn (string $provider): array => $this->payments->get($provider)->getWebhookEvents()),
            // Every registered payment provider name — lets the Core settings template render the info
            // partial for ANY payment section generically, so a new provider never touches that template.
            new TwigFunction('payment_provider_names', fn (): array => $this->payments->names()),
        ];
    }
}
