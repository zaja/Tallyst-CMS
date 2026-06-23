<?php

namespace App\Tests\FormBuilder;

use App\Settings\SettingsManager;
use PHPUnit\Framework\TestCase;
use Tallyst\FormBuilder\Payment\StripeProcessor;

/**
 * getMode() derives test/live/unconfigured from the EFFECTIVE secret key (the Postavke setting,
 * decrypted by SettingsManager, else the env fallback) — drives the admin mode badge.
 */
class StripeProcessorModeTest extends TestCase
{
    private function processor(string $settingValue, string $envKey = ''): StripeProcessor
    {
        $settings = $this->createStub(SettingsManager::class);
        $settings->method('get')->willReturn('' === $settingValue ? null : $settingValue);

        return new StripeProcessor($settings, $envKey, '');
    }

    public function testTestKeyFromSetting(): void
    {
        self::assertSame('test', $this->processor('sk_test_abc')->getMode());
    }

    public function testLiveKeyFromSetting(): void
    {
        self::assertSame('live', $this->processor('sk_live_abc')->getMode());
    }

    public function testFallsBackToEnvWhenSettingEmpty(): void
    {
        self::assertSame('test', $this->processor('', 'sk_test_env')->getMode());
    }

    public function testUnconfiguredWhenBothEmpty(): void
    {
        self::assertSame('unconfigured', $this->processor('', '')->getMode());
    }

    public function testDashboardUrl(): void
    {
        $order = (new \Tallyst\FormBuilder\Entity\Order())->setProviderPaymentIntentId('pi_123');

        self::assertSame('https://dashboard.stripe.com/test/payments/pi_123', $this->processor('sk_test_abc')->dashboardUrl($order));
        self::assertSame('https://dashboard.stripe.com/payments/pi_123', $this->processor('sk_live_abc')->dashboardUrl($order));
        self::assertNull($this->processor('sk_test_abc')->dashboardUrl(new \Tallyst\FormBuilder\Entity\Order()), 'no payment intent → no link');
    }
}
