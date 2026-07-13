<?php

namespace App\Tests\FormBuilder;

use App\Settings\SettingsManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\FormType;
use Tallyst\FormBuilder\Entity\Order;
use Tallyst\FormBuilder\Payment\DodoProcessor;

/**
 * Faza 6 K4 — the FIRST real change to the Dodo money path: createCheckout charges the CHOSEN sellable unit
 * (order.providerUnitId), falling back to the form's legacy single dodoProductId. Pure unit test with a
 * MockHttpClient capturing the POST body — no network. Also pins the §12 money-safety property: because Dodo
 * ignores `amount` for a fixed-price product (charges the product's own price), a null/0 display-cache amount
 * can NEVER mischarge — the checkout still targets the correct product_id.
 */
class DodoCheckoutTest extends TestCase
{
    /** @param callable(array<string,mixed>):void $captureBody */
    private function dodo(callable $captureBody): DodoProcessor
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($captureBody): MockResponse {
            $captureBody(json_decode((string) ($options['body'] ?? '{}'), true) ?: []);

            return new MockResponse(json_encode(['checkout_url' => 'https://dodo.test/checkout/abc', 'session_id' => 'sess_1']), ['http_code' => 200]);
        });

        return new DodoProcessor($http, $this->createStub(SettingsManager::class), new NullLogger(), 'dodo_test_key', '', 'test');
    }

    private function order(?string $providerUnitId, ?string $legacyProductId, int $amountMinor): Order
    {
        $form = (new FormDefinition())->setFormType(FormType::DIGITAL_MOR)->setMorProvider('dodo');
        if (null !== $legacyProductId) {
            $form->setDodoProductId($legacyProductId);
        }
        $order = (new Order())->setForm($form)->setAmountMinor($amountMinor);
        if (null !== $providerUnitId) {
            $order->setProviderUnitId($providerUnitId);
        }

        return $order;
    }

    public function testChargesTheChosenSellableUnit(): void
    {
        $body = [];
        $url = $this->dodo(function (array $b) use (&$body): void { $body = $b; })
            ->createCheckout($this->order('pdt_chosen', 'pdt_legacy', 4900), 'https://ret', 'https://cancel');

        self::assertSame('pdt_chosen', $body['product_cart'][0]['product_id'], 'the chosen unit is charged, not the legacy product');
        self::assertSame('https://dodo.test/checkout/abc', $url);
    }

    public function testFallsBackToLegacyDodoProductId(): void
    {
        // No providerUnitId (a not-yet-migrated single-product form) → the legacy dodoProductId is used.
        $body = [];
        $this->dodo(function (array $b) use (&$body): void { $body = $b; })
            ->createCheckout($this->order(null, 'pdt_legacy', 4900), 'https://ret', 'https://cancel');

        self::assertSame('pdt_legacy', $body['product_cart'][0]['product_id']);
    }

    public function testMoneySafetyNullCacheStillTargetsTheCorrectProduct(): void
    {
        // §12: amountMinor is a DISPLAY CACHE (may be 0 when a unit was never price-refreshed). Dodo ignores
        // `amount` for a fixed-price product → it charges the product's OWN price. Prove the checkout still
        // POSTs the correct product_id (so Dodo charges THAT unit) and succeeds, regardless of a 0 amount.
        $body = [];
        $url = $this->dodo(function (array $b) use (&$body): void { $body = $b; })
            ->createCheckout($this->order('pdt_chosen', 'pdt_legacy', 0), 'https://ret', 'https://cancel');

        self::assertSame('pdt_chosen', $body['product_cart'][0]['product_id'], 'a 0 display cache never changes which product is charged');
        self::assertSame(0, $body['product_cart'][0]['amount']);
        self::assertSame('https://dodo.test/checkout/abc', $url, 'checkout succeeds even with a 0 amount');
    }

    public function testRefusesSelfBilledVariantsOnAMoRForm(): void
    {
        // Defensive backstop: a MoR order's form must never carry self-billed price variants.
        $form = (new FormDefinition())->setFormType(FormType::DIGITAL_MOR)->setMorProvider('dodo')
            ->setVariants([['label' => 'X', 'priceMinor' => 100]]);
        $order = (new Order())->setForm($form)->setProviderUnitId('pdt_x')->setAmountMinor(100);

        $this->expectException(\RuntimeException::class);
        $this->dodo(static function (): void {})->createCheckout($order, 'https://ret', 'https://cancel');
    }
}
