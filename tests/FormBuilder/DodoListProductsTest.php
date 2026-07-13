<?php

namespace App\Tests\FormBuilder;

use App\Settings\SettingsManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tallyst\FormBuilder\Payment\DodoProcessor;

/**
 * Faza 5 K4: DodoProcessor::listUnits() offers ONLY fixed-price one-time products for the picker. It asks
 * Dodo for one-time, non-archived products (query recurring=false + archived=false) AND drops any recurring /
 * usage-based / pay-what-you-want item LOCALLY (belt-and-suspenders if the API ignores a filter). Description
 * is carried through for prefill. Pure unit test with a MockHttpClient — no network, no checkout path touched.
 */
class DodoListProductsTest extends TestCase
{
    /** @param array<string, mixed> $body */
    private function dodo(array $body, ?array &$capturedUrl = null): DodoProcessor
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($body, &$capturedUrl): MockResponse {
            $capturedUrl = $url;

            return new MockResponse(json_encode($body), ['http_code' => 200]);
        });
        $settings = $this->createStub(SettingsManager::class); // get() → null → falls back to the env key

        return new DodoProcessor($http, $settings, new NullLogger(), 'dodo_test_key', '', 'test');
    }

    public function testSendsRecurringAndArchivedFilters(): void
    {
        $url = null;
        $this->dodo(['items' => []], $url)->listUnits();

        self::assertStringContainsString('recurring=false', (string) $url);
        self::assertStringContainsString('archived=false', (string) $url);
        self::assertStringContainsString('page_size=100', (string) $url);
    }

    public function testDropsRecurringAndPayWhatYouWantKeepsOneTime(): void
    {
        $products = $this->dodo(['items' => [
            [
                'product_id' => 'pdt_one', 'name' => 'One-time', 'description' => 'A one-time thing',
                'price' => 4900, 'currency' => 'USD', 'is_recurring' => false,
                'price_detail' => ['one_time_price' => ['price' => 4900, 'currency' => 'USD', 'pay_what_you_want' => false]],
            ],
            // The API "ignored" the recurring=false filter → we must drop it locally.
            ['product_id' => 'pdt_sub', 'name' => 'Subscription', 'is_recurring' => true, 'price_detail' => ['recurring_price' => ['price' => 999]]],
            // Usage-based (via price_detail) → dropped even without is_recurring.
            ['product_id' => 'pdt_use', 'name' => 'Metered', 'is_recurring' => false, 'price_detail' => ['usage_based_price' => ['price' => 10]]],
            // Pay-what-you-want → dropped (variable price unsupported).
            ['product_id' => 'pdt_pwyw', 'name' => 'Donate', 'is_recurring' => false, 'price_detail' => ['one_time_price' => ['pay_what_you_want' => true]]],
        ]])->listUnits();

        self::assertCount(1, $products, 'only the fixed-price one-time product survives');
        self::assertSame('pdt_one', $products[0]['id']);
        self::assertSame('A one-time thing', $products[0]['description'], 'description carried through');
        self::assertSame(4900, $products[0]['priceMinor']);
        self::assertSame('USD', $products[0]['currency']);
    }

    public function testUnconfiguredReturnsEmptyWithoutHttp(): void
    {
        $http = new MockHttpClient(static fn () => throw new \RuntimeException('must not call Dodo when unconfigured'));
        $settings = $this->createStub(SettingsManager::class);
        // No env key + no setting → not configured → no HTTP.
        $dodo = new DodoProcessor($http, $settings, new NullLogger(), '', '', 'test');

        self::assertSame([], $dodo->listUnits());
    }

    // --- isSellableUnit (save-time guard for a manually-typed id) ---

    public function testIsSellableProductAcceptsOneTime(): void
    {
        $item = ['product_id' => 'pdt_one', 'is_recurring' => false, 'price_detail' => ['one_time_price' => ['price' => 4900, 'pay_what_you_want' => false]]];
        self::assertTrue($this->dodoForProduct($item)->isSellableUnit('pdt_one'));
    }

    public function testIsSellableProductRejectsRecurringAndPwyw(): void
    {
        $recurring = ['product_id' => 'pdt_sub', 'is_recurring' => true, 'price_detail' => ['recurring_price' => ['price' => 999]]];
        self::assertFalse($this->dodoForProduct($recurring)->isSellableUnit('pdt_sub'));

        $pwyw = ['product_id' => 'pdt_pwyw', 'is_recurring' => false, 'price_detail' => ['one_time_price' => ['pay_what_you_want' => true]]];
        self::assertFalse($this->dodoForProduct($pwyw)->isSellableUnit('pdt_pwyw'));
    }

    public function testIsSellableProductNullWhenNotFoundOrUnconfigured(): void
    {
        // 404 → can't confirm it's bad → null (the caller warns, never hard-rejects).
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse('{}', ['http_code' => 404]));
        $settings = $this->createStub(SettingsManager::class);
        self::assertNull((new DodoProcessor($http, $settings, new NullLogger(), 'dodo_test_key', '', 'test'))->isSellableUnit('pdt_x'));

        // Unconfigured → null, no HTTP.
        $noHttp = new MockHttpClient(static fn () => throw new \RuntimeException('must not call'));
        self::assertNull((new DodoProcessor($noHttp, $settings, new NullLogger(), '', '', 'test'))->isSellableUnit('pdt_x'));
    }

    /** @param array<string, mixed> $item */
    private function dodoForProduct(array $item): DodoProcessor
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse(json_encode($item), ['http_code' => 200]));

        return new DodoProcessor($http, $this->createStub(SettingsManager::class), new NullLogger(), 'dodo_test_key', '', 'test');
    }

    // --- fetchUnit (the "refresh from Dodo" button, Faza 5 K7) ---

    public function testFetchProductInfoReturnsLiveData(): void
    {
        $item = [
            'product_id' => 'pdt_one', 'name' => 'Arca Pro', 'description' => 'Updated blurb',
            'price' => 3900, 'currency' => 'EUR', 'is_recurring' => false,
            'price_detail' => ['one_time_price' => ['price' => 3900, 'currency' => 'EUR', 'pay_what_you_want' => false]],
        ];
        $info = $this->dodoForProduct($item)->fetchUnit('pdt_one');

        self::assertNotNull($info);
        self::assertTrue($info['found']);
        self::assertSame('Arca Pro', $info['name']);
        self::assertSame('Updated blurb', $info['description']);
        self::assertSame(3900, $info['priceMinor']);
        self::assertSame('EUR', $info['currency']);
        self::assertTrue($info['sellable']);
        self::assertFalse($info['archived']);
    }

    public function testFetchProductInfoFlagsUnsellable(): void
    {
        $recurring = ['product_id' => 'pdt_sub', 'is_recurring' => true, 'price_detail' => ['recurring_price' => ['price' => 999]]];
        $info = $this->dodoForProduct($recurring)->fetchUnit('pdt_sub');

        self::assertNotNull($info);
        self::assertTrue($info['found']);
        self::assertFalse($info['sellable'], 'a subscription is flagged not-sellable so the JS can warn');
    }

    public function testFetchProductInfoReportsArchivedWhenPresent(): void
    {
        $item = ['product_id' => 'pdt_arc', 'archived' => true, 'is_recurring' => false, 'price_detail' => ['one_time_price' => ['price' => 100]]];
        $info = $this->dodoForProduct($item)->fetchUnit('pdt_arc');

        self::assertNotNull($info);
        self::assertTrue($info['archived']);
    }

    public function testFetchProductInfoNotFoundOn404(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse('{}', ['http_code' => 404]));
        $info = (new DodoProcessor($http, $this->createStub(SettingsManager::class), new NullLogger(), 'dodo_test_key', '', 'test'))->fetchUnit('pdt_x');

        self::assertSame(['found' => false], $info);
    }

    public function testFetchProductInfoNullOnErrorOrUnconfigured(): void
    {
        // Transient 500 → null (couldn't fetch).
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse('{}', ['http_code' => 500]));
        self::assertNull((new DodoProcessor($http, $this->createStub(SettingsManager::class), new NullLogger(), 'dodo_test_key', '', 'test'))->fetchUnit('pdt_x'));

        // Unconfigured → null, no HTTP.
        $noHttp = new MockHttpClient(static fn () => throw new \RuntimeException('must not call'));
        self::assertNull((new DodoProcessor($noHttp, $this->createStub(SettingsManager::class), new NullLogger(), '', '', 'test'))->fetchUnit('pdt_x'));
    }
}
