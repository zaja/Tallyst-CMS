<?php

namespace App\Tests\FormBuilder;

use App\Settings\SettingsManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tallyst\FormBuilder\Payment\PayPalProcessor;

/**
 * getMode() reports test/live/unconfigured from the EFFECTIVE config (settings ?: env). PayPal has
 * no key prefix, so mode is the explicit paypal_mode toggle — sandbox shows as "test" for the badge.
 */
class PayPalProcessorModeTest extends TestCase
{
    /**
     * @param array<string, string> $settings
     */
    private function processor(array $settings, string $idEnv = '', string $secretEnv = '', string $modeEnv = ''): PayPalProcessor
    {
        $manager = $this->createStub(SettingsManager::class);
        $manager->method('get')->willReturnCallback(static fn (string $key) => $settings[$key] ?? null);

        return new PayPalProcessor(
            $this->createStub(HttpClientInterface::class),
            $manager,
            new ArrayAdapter(),
            new NullLogger(),
            $idEnv,
            $secretEnv,
            '',
            $modeEnv,
        );
    }

    public function testSandboxConfiguredIsTest(): void
    {
        $p = $this->processor(['paypal_client_id' => 'id', 'paypal_client_secret' => 'sec', 'paypal_mode' => 'sandbox']);
        self::assertSame('test', $p->getMode());
    }

    public function testLiveConfiguredIsLive(): void
    {
        $p = $this->processor(['paypal_client_id' => 'id', 'paypal_client_secret' => 'sec', 'paypal_mode' => 'live']);
        self::assertSame('live', $p->getMode());
    }

    public function testDefaultsToSandboxWhenModeUnset(): void
    {
        $p = $this->processor(['paypal_client_id' => 'id', 'paypal_client_secret' => 'sec']);
        self::assertSame('test', $p->getMode());
    }

    public function testConfiguredViaEnvFallback(): void
    {
        $p = $this->processor([], 'id', 'sec', 'live');
        self::assertSame('live', $p->getMode());
    }

    public function testUnconfiguredWhenNoCredentials(): void
    {
        self::assertSame('unconfigured', $this->processor([])->getMode());
    }

    public function testDashboardUrl(): void
    {
        // dashboardUrl uses the order's RECORDED mode ('test'/'live' from getMode() at creation).
        $sandbox = (new \Tallyst\FormBuilder\Entity\Order())->setProviderPaymentIntentId('CAP-1')->setPaymentMode('test');
        $live = (new \Tallyst\FormBuilder\Entity\Order())->setProviderPaymentIntentId('CAP-1')->setPaymentMode('live');

        self::assertSame('https://www.sandbox.paypal.com/activity/', $this->processor([])->dashboardUrl($sandbox));
        self::assertSame('https://www.paypal.com/activity/', $this->processor([])->dashboardUrl($live));
        self::assertNull($this->processor([])->dashboardUrl(new \Tallyst\FormBuilder\Entity\Order()), 'no capture id → no link');
    }
}
