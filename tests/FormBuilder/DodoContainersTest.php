<?php

namespace App\Tests\FormBuilder;

use App\Settings\SettingsManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tallyst\FormBuilder\Payment\DodoProcessor;

/**
 * Faza 7 K1: listContainers() (GET /product-collections) + containerUnits() (GET /product-collections/{id} →
 * groups[].products[]) — the read-only source for "import from collection". Shapes are the LIVE-PROBED ones
 * (2026-07-13): the collection detail returns the products nested under groups[].products[], each with
 * product_id / name / status / price / price_detail{type, pay_what_you_want}. Pure unit test with a
 * MockHttpClient — no network, no checkout path touched.
 */
class DodoContainersTest extends TestCase
{
    /** @param callable(string):MockResponse $router routes by URL */
    private function dodo(callable $router): DodoProcessor
    {
        $http = new MockHttpClient(fn (string $method, string $url): MockResponse => $router($url));

        return new DodoProcessor($http, $this->createStub(SettingsManager::class), new NullLogger(), 'dodo_test_key', '', 'test');
    }

    private function product(string $id, string $name, array $priceDetail, bool $status = true, int $price = 4900): array
    {
        return ['product_id' => $id, 'status' => $status, 'name' => $name, 'description' => 'desc '.$name,
            'price' => $price, 'currency' => 'EUR', 'price_detail' => $priceDetail];
    }

    // --- listContainers ---

    public function testListContainersMapsCollections(): void
    {
        $body = ['items' => [
            ['id' => 'pdc_1', 'name' => 'Collection 1', 'description' => 'Desc 1', 'image' => 'https://x', 'products_count' => 2],
            ['id' => 'pdc_2', 'name' => 'Collection 2', 'description' => null, 'products_count' => 0],
        ]];
        $out = $this->dodo(fn () => new MockResponse(json_encode($body), ['http_code' => 200]))->listContainers();

        self::assertCount(2, $out);
        self::assertSame(['id' => 'pdc_1', 'name' => 'Collection 1', 'description' => 'Desc 1', 'productsCount' => 2], $out[0]);
        self::assertNull($out[1]['description']);
        self::assertSame(0, $out[1]['productsCount']);
    }

    public function testListContainersEmptyWhenUnconfigured(): void
    {
        $http = new MockHttpClient(static fn () => throw new \RuntimeException('must not call when unconfigured'));
        $dodo = new DodoProcessor($http, $this->createStub(SettingsManager::class), new NullLogger(), '', '', 'test');
        self::assertSame([], $dodo->listContainers());
    }

    // --- containerUnits ---

    public function testContainerUnitsPartitionsAcrossAllGroups(): void
    {
        // Live-probed shape: meta + groups[].products[]. TWO groups; a mix of sellable + every skip reason.
        $detail = [
            'id' => 'pdc_1', 'name' => 'Collection 1', 'description' => 'Nice **desc**',
            'groups' => [
                ['group_id' => 'g1', 'products' => [
                    $this->product('pdt_ok1', 'Personal', ['type' => 'one_time_price', 'pay_what_you_want' => false], true, 2900),
                    $this->product('pdt_sub', 'Monthly', ['type' => 'recurring_price']),
                    $this->product('pdt_use', 'Metered', ['type' => 'usage_based_price']),
                ]],
                ['group_id' => 'g2', 'products' => [
                    $this->product('pdt_ok2', 'Team', ['type' => 'one_time_price', 'pay_what_you_want' => false], true, 4900),
                    $this->product('pdt_pwyw', 'Donate', ['type' => 'one_time_price', 'pay_what_you_want' => true]),
                    $this->product('pdt_off', 'Inactive', ['type' => 'one_time_price', 'pay_what_you_want' => false], false),
                ]],
            ],
        ];
        $res = $this->dodo(fn () => new MockResponse(json_encode($detail), ['http_code' => 200]))->containerUnits('pdc_1');

        self::assertNotNull($res);
        self::assertSame('Collection 1', $res['name']);
        self::assertSame('Nice **desc**', $res['description']);

        // Sellable units (both groups), in order.
        self::assertSame(['pdt_ok1', 'pdt_ok2'], array_column($res['units'], 'id'));
        self::assertSame('Personal', $res['units'][0]['name']);
        self::assertSame(2900, $res['units'][0]['priceMinor']);
        self::assertSame('EUR', $res['units'][0]['currency']);

        // Skipped, each with a reason.
        $skipped = [];
        foreach ($res['skipped'] as $s) {
            $skipped[$s['name']] = $s['reason'];
        }
        self::assertSame('recurring', $skipped['Monthly']);
        self::assertSame('usage_based', $skipped['Metered']);
        self::assertSame('pay_what_you_want', $skipped['Donate']);
        self::assertSame('inactive', $skipped['Inactive']);
        self::assertCount(4, $res['skipped']);
    }

    public function testContainerUnitsCarryTaxInclusive(): void
    {
        // Faza 8: tax_inclusive (top-level or price_detail) flows onto each unit → the front's exclusive note.
        $detail = ['id' => 'pdc_t', 'name' => 'Tax', 'description' => '', 'groups' => [
            ['products' => [
                ['product_id' => 'pdt_incl', 'status' => true, 'name' => 'Incl', 'price' => 4900, 'currency' => 'EUR', 'tax_inclusive' => true, 'price_detail' => ['type' => 'one_time_price']],
                ['product_id' => 'pdt_excl', 'status' => true, 'name' => 'Excl', 'price' => 4900, 'currency' => 'EUR', 'price_detail' => ['type' => 'one_time_price', 'tax_inclusive' => false]],
            ]],
        ]];
        $res = $this->dodo(fn () => new MockResponse(json_encode($detail), ['http_code' => 200]))->containerUnits('pdc_t');

        self::assertTrue($res['units'][0]['taxInclusive'], 'top-level tax_inclusive=true');
        self::assertFalse($res['units'][1]['taxInclusive'], 'price_detail.tax_inclusive=false');
    }

    public function testContainerUnitsEmptyCollection(): void
    {
        $detail = ['id' => 'pdc_e', 'name' => 'Empty', 'description' => '', 'groups' => []];
        $res = $this->dodo(fn () => new MockResponse(json_encode($detail), ['http_code' => 200]))->containerUnits('pdc_e');

        self::assertNotNull($res);
        self::assertSame([], $res['units']);
        self::assertSame([], $res['skipped']);
        self::assertSame('Empty', $res['name']);
    }

    public function testContainerUnitsAllUnsupported(): void
    {
        $detail = ['id' => 'pdc_x', 'name' => 'Subs', 'description' => '', 'groups' => [
            ['products' => [
                $this->product('pdt_s1', 'Sub A', ['type' => 'recurring_price']),
                $this->product('pdt_s2', 'Sub B', ['type' => 'recurring_price']),
            ]],
        ]];
        $res = $this->dodo(fn () => new MockResponse(json_encode($detail), ['http_code' => 200]))->containerUnits('pdc_x');

        self::assertNotNull($res);
        self::assertSame([], $res['units'], 'nothing sellable → empty units');
        self::assertCount(2, $res['skipped']);
    }

    public function testContainerUnitsNullOnErrorOrUnconfigured(): void
    {
        // 404 / any non-2xx → null (the endpoint maps it to a clear message).
        self::assertNull($this->dodo(fn () => new MockResponse('{}', ['http_code' => 404]))->containerUnits('pdc_gone'));
        self::assertNull($this->dodo(fn () => new MockResponse('{}', ['http_code' => 500]))->containerUnits('pdc_x'));

        // Unconfigured → null, no HTTP.
        $http = new MockHttpClient(static fn () => throw new \RuntimeException('must not call'));
        self::assertNull((new DodoProcessor($http, $this->createStub(SettingsManager::class), new NullLogger(), '', '', 'test'))->containerUnits('pdc_x'));
    }
}
