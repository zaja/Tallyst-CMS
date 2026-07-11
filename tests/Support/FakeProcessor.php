<?php

namespace App\Tests\Support;

use App\Settings\SettingsManager;
use Tallyst\FormBuilder\Entity\Order;
use Tallyst\FormBuilder\Payment\PaymentProcessorInterface;
use Tallyst\FormBuilder\Payment\WebhookResult;

/**
 * Test-only payment processor (NO HTTP) so functional tests can drive the submit → startCheckout flow
 * without hitting Stripe/PayPal. Registered only in the test container (config/services.yaml when@test),
 * and INVISIBLE unless the `fake_processor_enabled` setting is truthy — so it never pollutes other tests'
 * provider lists. createCheckout records the charged amount on the session id and returns the successUrl.
 */
class FakeProcessor implements PaymentProcessorInterface
{
    public const NAME = 'faketest';

    public function __construct(private readonly SettingsManager $settings)
    {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function isConfigured(): bool
    {
        return (bool) $this->settings->get('fake_processor_enabled');
    }

    public function getMode(): string
    {
        return 'test';
    }

    public function getWebhookEvents(): array
    {
        return [];
    }

    public function finalizeReturn(Order $order): void
    {
    }

    public function refund(Order $order): void
    {
    }

    public function dashboardUrl(Order $order): ?string
    {
        return null;
    }

    public function parseSignedWebhook(string $payload, array $headers): WebhookResult
    {
        throw new \LogicException('FakeProcessor::parseSignedWebhook is not used in tests.');
    }

    public function createCheckout(Order $order, string $successUrl, string $cancelUrl): string
    {
        // No HTTP. Record the amount we were asked to charge so the test can read it back.
        $order->setProviderSessionId('fake_'.$order->getAmountMinor());

        return $successUrl;
    }
}
