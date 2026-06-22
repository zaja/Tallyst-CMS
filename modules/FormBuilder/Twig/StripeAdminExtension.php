<?php

namespace Tallyst\FormBuilder\Twig;

use Tallyst\FormBuilder\Controller\StripeWebhookController;
use Tallyst\FormBuilder\Payment\StripeProcessor;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the Stripe-admin info (mode badge + required webhook events) to the Postavke → Stripe
 * info partial. Twig-level (runtime) so the Core settings template can use it without a Core→
 * FormBuilder PHP dependency — same pattern as Media's render_branding/media_img.
 */
class StripeAdminExtension extends AbstractExtension
{
    public function __construct(private readonly StripeProcessor $stripe)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('stripe_mode', $this->stripe->getMode(...)),
            new TwigFunction('stripe_webhook_events', static fn (): array => StripeWebhookController::REQUIRED_WEBHOOK_EVENTS),
        ];
    }
}
