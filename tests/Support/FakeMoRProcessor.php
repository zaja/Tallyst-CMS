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

    // Faza 6 K2/K3: the MoR sellable-unit contract. No HTTP — deterministic by id PREFIX, so functional
    // tests can drive the per-unit save guard: `sub_*` = not sellable (subscription/PWYW → reject),
    // `ok_*` = a fixed-price one-time unit (accept), anything else = unverifiable (warn). listUnits is empty
    // (the builder falls back to a manual id field), which is what the tests exercise.

    public function listUnits(): array
    {
        return [];
    }

    public function fetchUnit(string $id): ?array
    {
        $sellable = $this->isSellableUnit($id);
        if (null === $sellable) {
            return null;
        }

        return ['found' => true, 'name' => 'Fake '.$id, 'description' => '', 'priceMinor' => 4900, 'currency' => 'EUR', 'sellable' => $sellable, 'archived' => false];
    }

    public function isSellableUnit(string $id): ?bool
    {
        if (str_starts_with($id, 'sub_')) {
            return false; // a "subscription" / pay-what-you-want unit — Tallyst can't sell it
        }
        if (str_starts_with($id, 'ok_')) {
            return true; // a fixed-price one-time unit
        }

        return null; // unverifiable
    }

    // Faza 7: unit CONTAINERS (Dodo collections). Deterministic — `col_full` has 2 sellable + 1 skipped,
    // `col_empty` has none. Lets functional tests drive the "import from collection" flow without HTTP.

    public function listContainers(): array
    {
        return [
            ['id' => 'col_full', 'name' => 'Fake Collection', 'description' => 'A fake collection', 'productsCount' => 3],
            ['id' => 'col_empty', 'name' => 'Empty Collection', 'description' => null, 'productsCount' => 0],
        ];
    }

    public function containerUnits(string $containerId): ?array
    {
        if ('col_full' === $containerId) {
            return [
                'name' => 'Fake Collection',
                'description' => 'A fake collection',
                'units' => [
                    ['id' => 'ok_a', 'name' => 'Personal', 'description' => null, 'price' => '29.00 EUR', 'priceMinor' => 2900, 'currency' => 'EUR'],
                    ['id' => 'ok_b', 'name' => 'Team', 'description' => null, 'price' => '49.00 EUR', 'priceMinor' => 4900, 'currency' => 'EUR'],
                ],
                'skipped' => [['name' => 'Monthly', 'reason' => 'recurring']],
            ];
        }
        if ('col_empty' === $containerId) {
            return ['name' => 'Empty Collection', 'description' => '', 'units' => [], 'skipped' => []];
        }

        return null; // not found
    }
}
