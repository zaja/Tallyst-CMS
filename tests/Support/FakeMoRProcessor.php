<?php

namespace App\Tests\Support;

use App\Settings\SettingsManager;
use Tallyst\FormBuilder\Entity\Order;
use Tallyst\FormBuilder\Payment\MerchantOfRecordInterface;
use Tallyst\FormBuilder\Payment\PaymentProcessorInterface;
use Tallyst\FormBuilder\Payment\WebhookResult;

/**
 * Test-only Merchant-of-Record processor (NO HTTP) — like FakeProcessor but marked MerchantOfRecordInterface,
 * so functional tests can prove the MoR money path in startCheckout WITHOUT hitting Dodo's API: shipping +
 * Tallyst tax must be skipped for a MoR order. Same `fake_processor_enabled` gate.
 */
class FakeMoRProcessor implements PaymentProcessorInterface, MerchantOfRecordInterface
{
    public const NAME = 'fakemor';

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
        throw new \LogicException('FakeMoRProcessor::parseSignedWebhook is not used in tests.');
    }

    public function createCheckout(Order $order, string $successUrl, string $cancelUrl): string
    {
        $order->setProviderSessionId('fakemor_'.$order->getAmountMinor());

        return $successUrl;
    }
}
