<?php

namespace Tallyst\FormBuilder\Payment;

use App\Settings\SettingsManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Tallyst\FormBuilder\Entity\Order;

/**
 * DodoPayments — a Merchant-of-Record (MoR) provider. Dodo is the legal seller: it calculates,
 * collects and remits sales tax / VAT / GST itself and pays the admin the net, so Tallyst's own
 * inclusive TaxCalculator is NOT applied to a Dodo order (see MerchantOfRecordInterface + the gate
 * in FormSubmitController).
 *
 * Direct Symfony HttpClient (NO Dodo SDK — it's beta; keeping composer.json untouched avoids the
 * Flex-strip risk and a beta dep in the frozen core). Auth is a plain Bearer API key (no OAuth,
 * unlike PayPal). Webhooks follow the Standard Webhooks spec (HMAC-SHA256).
 *
 * Keys come from Postavke → Dodo (encrypted) with an env fallback — same pattern as Stripe/PayPal.
 * Mode is EXPLICIT (test/live) from the dodo_mode setting: Dodo keys carry no test/live marker and
 * the API host differs per mode.
 *
 * ✅ LIVE-PROVEN (Faza 6–8: real test purchases orders 33–46 + API probes 2026-07-13/15) — the money path
 * below is confirmed against the LIVE Dodo API, not just the brief: the checkout endpoint + request shape,
 * that Dodo ignores product_cart[].amount for a fixed-price product, the `type` + metadata.order_id
 * correlation, the webhook secret encoding, the hosted-checkout auto-capture (finalizeReturn is a no-op),
 * the entitlement/licence path (entitlement_grant.created → data.license_key.key), and the product
 * LIST envelope + price shapes.
 * ⚠ STILL UNVERIFIED — the REFUND path ONLY: the `refund.succeeded` event type and the `/refunds`
 * endpoint/body are still assumptions from the brief (no live Dodo refund has ever been run). Verify these
 * before relying on refunds; everything else on the money path is proven.
 */
class DodoProcessor implements PaymentProcessorInterface, MerchantOfRecordInterface
{
    // ✅ Live-proven (Faza 6–8): real test purchases went through these hosts (test/live per dodo_mode).
    private const HOST_TEST = 'https://test.dodopayments.com';
    private const HOST_LIVE = 'https://live.dodopayments.com';

    /** Reject webhooks whose timestamp is older/newer than this (replay guard), in seconds. */
    private const WEBHOOK_TOLERANCE = 300;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly SettingsManager $settings,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(DODO_API_KEY)%')]
        private readonly string $apiKeyEnv,
        #[Autowire('%env(DODO_WEBHOOK_SECRET)%')]
        private readonly string $webhookSecretEnv,
        #[Autowire('%env(DODO_MODE)%')]
        private readonly string $modeEnv,
    ) {
    }

    public function getName(): string
    {
        return 'dodo';
    }

    /**
     * Provider-level "can I call Dodo at all" = the API key is set (mirrors Stripe's single-key check;
     * the webhook secret only verifies inbound calls, not outbound ones). This is SEPARATE from the
     * per-form "does this form have a linked Dodo product" check (enforced in the checkout flow) — a
     * configured provider can still refuse a form that has no dodoProductId.
     */
    public function isConfigured(): bool
    {
        return '' !== $this->apiKey();
    }

    public function getMode(): string
    {
        if ('' === $this->apiKey()) {
            return 'unconfigured';
        }

        return 'live' === $this->mode() ? 'live' : 'test';
    }

    public function getWebhookEvents(): array
    {
        // The events the admin must subscribe in the Dodo dashboard. We ACT on payment.succeeded
        // (paid) + the refund event + entitlement_grant.created (licence capture, Phase 2);
        // payment.failed is subscribed for visibility (logged only).
        // entitlement_grant.created: ✅ live-proven (Faza 8 K0 — carried data.license_key.key on real orders).
        // payment.succeeded/payment.failed: ✅ live-proven (real orders reached paid via the webhook).
        // refund.succeeded: ⚠ still assumed — no live Dodo refund has been run; verify the exact type.
        return ['payment.succeeded', 'payment.failed', 'refund.succeeded', 'entitlement_grant.created'];
    }

    /** No reliable per-transaction deep-link documented for Dodo yet — honest null (no dashboard button). */
    public function dashboardUrl(Order $order): ?string
    {
        return null;
    }

    public function createCheckout(Order $order, string $successUrl, string $cancelUrl): string
    {
        // Defensive backstop: a MoR form carries SELLABLE UNITS (morUnits), never self-billed price variants.
        // Self-billed variants on a MoR order would be a bug upstream — refuse rather than mischarge.
        if ($order->getForm()?->hasVariants()) {
            throw new \RuntimeException('Dodo (Merchant-of-Record) uses sellable units, not price variants; this form has variants.');
        }

        // Faza 6: charge the CHOSEN sellable unit — the buyer's pick, resolved server-side in startCheckout
        // and recorded on the order. Fall back to the form's legacy single dodoProductId (a not-yet-migrated
        // single-product form). '' → no unit linked → a clear error, never a dead checkout.
        $productId = $order->getProviderUnitId() ?: (string) $order->getForm()?->getDodoProductId();
        if ('' === $productId) {
            throw new \RuntimeException('This order has no linked Dodo product.');
        }

        // ✅ Live-proven (Faza 6–8): POST /checkouts with product_cart + metadata returns a hosted
        // checkout_url (+ a session id); real purchases completed against it.
        //
        // MONEY-SAFETY (Faza 6 §12) — LIVE-PROVEN: `product_cart[].amount` is Dodo's PAY-WHAT-YOU-WANT
        // override, honored ONLY for a product whose one_time_price.pay_what_you_want is TRUE. For a
        // FIXED-price product Dodo charges the product's OWN configured price and IGNORES `amount`. Verified
        // on the live test system: Tallyst price set to 100 EUR, Dodo product priced 49 EUR → Dodo charged
        // 49 EUR. Tallyst sells ONLY fixed-price one-time units (pay-what-you-want is rejected at save +
        // dropped from the picker), so `amount` is ignored for every unit we send. That matters because from
        // Faza 6 `amountMinor` is a DISPLAY CACHE (may be null→0 / stale) — since Dodo ignores it, a wrong/zero
        // cache can NEVER mischarge (the unit's own Dodo price is charged). We keep sending it bit-identically
        // (a harmless no-op that also feeds a PWYW product if one ever slipped through).
        $data = $this->json('POST', '/checkouts', [
            'product_cart' => [[
                'product_id' => $productId,
                'quantity' => 1,
                'amount' => $order->getAmountMinor(),
            ]],
            // Primary correlation key: Dodo echoes this back on the webhook (metadata.order_id).
            'metadata' => ['order_id' => (string) $order->getId()],
            'return_url' => $successUrl,
        ]);

        // Best-effort: store the session id if Dodo returns one (NOT the match key — we match by
        // metadata.order_id on the webhook, since the Dodo webhook may not carry the session id).
        $sessionId = $data['session_id'] ?? $data['id'] ?? null;
        if (is_string($sessionId) && '' !== $sessionId) {
            $order->setProviderSessionId($sessionId);
        }

        $url = $data['checkout_url'] ?? $data['url'] ?? null;
        if (!is_string($url) || '' === $url) {
            throw new \RuntimeException('Dodo checkout returned no checkout_url.');
        }

        return $url;
    }

    /**
     * Hosted checkout auto-captures (like Stripe Checkout) → no capture step on return. The webhook
     * stays the sole source of truth for `paid`. ✅ Live-proven (Faza 6–8): real orders reached `paid`
     * via the webhook with this method a no-op — Dodo needs no capture-on-return call.
     */
    public function finalizeReturn(Order $order): void
    {
    }

    public function parseSignedWebhook(string $payload, array $headers): WebhookResult
    {
        $this->verifyWebhook($payload, $headers);

        $event = json_decode($payload, true) ?: [];
        // ✅ Live-proven (Faza 6–8): Dodo webhooks use `type` (the `event_type` fallback is belt-and-braces).
        $type = (string) ($event['type'] ?? $event['event_type'] ?? '');
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];

        if ('payment.failed' === $type) {
            $this->logger->warning('Dodo payment.failed', ['payment_id' => $data['payment_id'] ?? null]);
        }

        $isPaid = 'payment.succeeded' === $type;
        // entitlement_grant.created: ✅ live-proven (Faza 8 K0). refund.succeeded: ⚠ still assumed (no live refund run).
        $isRefund = 'refund.succeeded' === $type;
        $isEntitlement = 'entitlement_grant.created' === $type;

        // Correlation: metadata.order_id we sent at checkout, echoed back by Dodo. Look in data.metadata
        // first, then a top-level metadata, then a top-level order_id. ✅ Live-proven (Faza 6–8): Dodo
        // echoes it in data.metadata.order_id — every paid order correlated correctly.
        // (Entitlement events carry empty metadata → orderId stays null; they correlate by payment_id.)
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata']
            : (is_array($event['metadata'] ?? null) ? $event['metadata'] : []);
        $orderId = $metadata['order_id'] ?? $event['metadata']['order_id'] ?? null;

        // payment_id is the bridge to the order for BOTH paid and entitlement events. ✅ Live-proven (Faza 8 K0).
        $paymentId = $data['payment_id'] ?? null;
        $email = $data['customer']['email'] ?? null;

        // Phase 2 passive capture. Payment fields (customer/finance) live on payment.succeeded; the
        // licence key lives on entitlement_grant.created (data.license_key.key). ✅ Live-proven (Faza 8 K0).
        $licenseKey = $isEntitlement ? ($data['license_key']['key'] ?? null) : null;
        $intOrNull = static fn (mixed $v): ?int => is_numeric($v) ? (int) $v : null;

        return new WebhookResult(
            eventType: $type,
            sessionId: null,
            paymentIntentId: is_string($paymentId) ? $paymentId : null,
            isPaid: $isPaid,
            customerEmail: is_string($email) ? $email : null,
            isRefund: $isRefund,
            orderId: is_scalar($orderId) ? (string) $orderId : null,
            isEntitlement: $isEntitlement,
            licenseKey: is_string($licenseKey) ? $licenseKey : null,
            customerName: is_string($data['customer']['name'] ?? null) ? $data['customer']['name'] : null,
            customerPhone: is_string($data['customer']['phone_number'] ?? null) ? $data['customer']['phone_number'] : null,
            invoiceUrl: is_string($data['invoice_url'] ?? null) ? $data['invoice_url'] : null,
            dodoTaxMinor: $intOrNull($data['tax'] ?? null),
            dodoTotalMinor: $intOrNull($data['total_amount'] ?? null),
            dodoSettlementMinor: $intOrNull($data['settlement_amount'] ?? null),
            dodoSettlementCurrency: is_string($data['settlement_currency'] ?? null) ? $data['settlement_currency'] : null,
        );
    }

    public function refund(Order $order): void
    {
        $paymentId = (string) $order->getProviderPaymentIntentId();
        if ('' === $paymentId) {
            throw new \RuntimeException('Order has no Dodo payment to refund.');
        }

        // ⚠ NOT yet live-proven: the /refunds endpoint + body are still brief assumptions — no live Dodo
        // refund has ever been run. Verify before relying on refunds. Full refund (v1 = full only, like Stripe/PayPal).
        $response = $this->request('POST', '/refunds', ['json' => ['payment_id' => $paymentId]]);
        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException('Dodo refund failed: '.$response->getContent(false));
        }
    }

    /**
     * List the Dodo products in the ACTIVE mode (test/live), for the per-form product picker. READ-ONLY
     * — never writes to Dodo (not a payment path). Each item carries a display `price` label PLUS the
     * structured `priceMinor` + `currency` + `description` used to one-time PREFILL the Tallyst fields.
     * Returns [] when the provider isn't configured (no HTTP) or on ANY error — the caller falls back
     * to a manual product-id input, never a hard failure.
     *
     * Faza 5 K4: Tallyst sells ONLY a fixed-price one-time product, so the picker must offer ONLY those.
     * We ask Dodo for one-time, non-archived products (query `recurring=false` + `archived=false`), AND —
     * belt-and-suspenders, in case the API ignores a filter — DROP any item that reports as recurring /
     * usage-based or "pay what you want" locally. A recurring/PWYW product can NEVER reach the dropdown.
     *
     * Query keys (page_size ≤ 100, page_number, recurring, archived) + the item fields (product_id, name,
     * description, price [minor units], currency, is_recurring, price_detail) are per the Dodo API reference.
     * ✅ Live-proven (probed 2026-07-15): the LIST envelope key is `items` (the `data`/bare-array fallbacks are belt-and-braces).
     *
     * Faza 6 K2: implements MerchantOfRecordInterface::listUnits() — a Dodo "sellable unit" is a product.
     *
     * @return list<array{id: string, name: string, description: ?string, price: ?string, priceMinor: ?int, currency: ?string}>
     */
    public function listUnits(): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $pageSize = 100;
        $maxPages = 10; // hard cap — never loop unbounded on a large / misbehaving catalogue
        $products = [];

        try {
            for ($page = 0; $page < $maxPages; ++$page) {
                $response = $this->request('GET', '/products', [
                    // Strings, not booleans: http_build_query turns false into "0" — Dodo wants "false".
                    'query' => ['page_number' => $page, 'page_size' => $pageSize, 'recurring' => 'false', 'archived' => 'false'],
                    'timeout' => 8,
                ]);
                if ($response->getStatusCode() >= 300) {
                    // Non-2xx → give up and let the caller fall back (don't throw into the form render).
                    $this->logger->warning('Dodo list-products returned {status}', ['status' => $response->getStatusCode()]);
                    break;
                }

                $data = $response->toArray(false);
                // ✅ Live-proven (probed 2026-07-15): `items` (the data/bare-array fallbacks are belt-and-braces).
                $items = $data['items'] ?? $data['data'] ?? (array_is_list($data) ? $data : []);
                if (!is_array($items) || [] === $items) {
                    break;
                }

                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $id = $item['product_id'] ?? $item['id'] ?? null;
                    if (!is_scalar($id) || '' === (string) $id) {
                        continue;
                    }
                    // Local guard: Tallyst can't sell a recurring/usage-based or pay-what-you-want product.
                    if (!$this->isOneTimeProduct($item) || $this->isPayWhatYouWant($item)) {
                        continue;
                    }
                    $price = $this->extractPrice($item);
                    $products[] = [
                        'id' => (string) $id,
                        'name' => is_scalar($item['name'] ?? null) && '' !== (string) $item['name'] ? (string) $item['name'] : (string) $id,
                        'description' => is_scalar($item['description'] ?? null) && '' !== (string) $item['description'] ? (string) $item['description'] : null,
                        'price' => $price['label'],
                        'priceMinor' => $price['minor'],
                        'currency' => $price['currency'],
                        'taxInclusive' => $this->taxInclusive($item),
                        'pricingMode' => $this->pricingMode($item),
                    ];
                }

                if (count($items) < $pageSize) {
                    break; // last page
                }
            }
        } catch (\Throwable $e) {
            // ANY failure (network, decode, auth) → empty list → the picker degrades to a manual input.
            $this->logger->warning('Dodo list-products failed: {error}', ['error' => $e->getMessage()]);

            return [];
        }

        return $products;
    }

    /**
     * A one-time product = NOT recurring and NOT usage-based. Uses the top-level is_recurring flag first,
     * then the price_detail union (whether keyed `recurring_price`/`usage_based_price` or tagged by `type`).
     *
     * @param array<string, mixed> $item
     */
    private function isOneTimeProduct(array $item): bool
    {
        if (true === ($item['is_recurring'] ?? null)) {
            return false;
        }

        $detail = $item['price_detail'] ?? null;
        if (is_array($detail)) {
            if (isset($detail['recurring_price']) || isset($detail['usage_based_price'])) {
                return false;
            }
            $type = is_scalar($detail['type'] ?? null) ? (string) $detail['type'] : null;
            if (in_array($type, ['recurring_price', 'usage_based_price'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A "pay what you want" product (variable price) — Tallyst has no variable-price concept, so it's
     * excluded. Tolerates both price_detail.pay_what_you_want and price_detail.one_time_price.pay_what_you_want.
     *
     * @param array<string, mixed> $item
     */
    private function isPayWhatYouWant(array $item): bool
    {
        $detail = $item['price_detail'] ?? null;
        if (!is_array($detail)) {
            return false;
        }
        if (true === ($detail['pay_what_you_want'] ?? null)) {
            return true;
        }
        $oneTime = $detail['one_time_price'] ?? null;

        return is_array($oneTime) && true === ($oneTime['pay_what_you_want'] ?? null);
    }

    /**
     * Whether the product's price is TAX-INCLUSIVE (Faza 8). ⚠ The flag lives in a DIFFERENT place per
     * endpoint (live-probed 2026-07-15):
     *   - LIST /products              → top-level `tax_inclusive` AND `price_detail.tax_inclusive`;
     *   - DETAIL /products/{id}       → ONLY `price.tax_inclusive` (the `price` object IS the one_time_price;
     *                                   there is NO top-level flag and NO `price_detail`);
     *   - COLLECTION /product-collections/{id} groups[].products[] → top-level AND `price_detail`.
     * So check ALL of: top-level, the `price` OBJECT, `price_detail`, and `price_detail.one_time_price`
     * (defensive, mirroring how isPayWhatYouWant tolerates the nested one_time_price). The FIRST bool wins.
     * Null when Dodo doesn't report it → the front shows the NEUTRAL note (unknown → never claim tax is
     * added). A DISPLAY cache like the price; the buyer sees the exclusive-tax warning only when this is FALSE.
     *
     * @param array<string, mixed> $item
     */
    private function taxInclusive(array $item): ?bool
    {
        if (is_bool($item['tax_inclusive'] ?? null)) {
            return $item['tax_inclusive'];
        }
        // DETAIL payload: `price` is the one_time_price OBJECT carrying tax_inclusive.
        $price = $item['price'] ?? null;
        if (is_array($price) && is_bool($price['tax_inclusive'] ?? null)) {
            return $price['tax_inclusive'];
        }
        $detail = $item['price_detail'] ?? null;
        if (is_array($detail)) {
            if (is_bool($detail['tax_inclusive'] ?? null)) {
                return $detail['tax_inclusive'];
            }
            $oneTime = $detail['one_time_price'] ?? null;
            if (is_array($oneTime) && is_bool($oneTime['tax_inclusive'] ?? null)) {
                return $oneTime['tax_inclusive'];
            }
        }

        return null;
    }

    /**
     * The product's PRICING MODE (Faza 8) — read from the top-level `pricing_mode` (live-probed 2026-07-15:
     * present at the TOP LEVEL of the LIST + DETAIL payloads, ABSENT on collection products). A string enum:
     *   - null / 'standard'          → ONE price for everyone;
     *   - 'by_currency' / 'by_country' → the provider LOCALISES the price per buyer region.
     * Used ONLY to word the front's inclusive-tax note ("may adjust to your region") — it NEVER touches the
     * charged amount. Null when absent/unknown (front → no localisation wording). A display cache like the price.
     *
     * @param array<string, mixed> $item
     */
    private function pricingMode(array $item): ?string
    {
        $mode = $item['pricing_mode'] ?? null;

        return is_string($mode) && '' !== $mode ? $mode : null;
    }

    /**
     * Fetch ONE product's current data by id (GET /products/{id}) — READ-ONLY, never a checkout path.
     * Returns:
     *   - null                        → could NOT fetch (unconfigured / transient API error),
     *   - ['found' => false]          → the product no longer exists on Dodo (404),
     *   - ['found' => true, name, description, priceMinor, currency, sellable, archived] → the live data.
     * `sellable` = a fixed-price one-time product (not recurring / usage-based / pay-what-you-want).
     * Used by the save-time guard (isSellableUnit) AND the "refresh from Dodo" button (Faza 5 K7).
     * Faza 6 K2: implements MerchantOfRecordInterface::fetchUnit() — a Dodo "unit" is a product.
     *
     * @return array{found: bool, name?: string, description?: string, priceMinor?: ?int, currency?: ?string, sellable?: bool, archived?: bool}|null
     */
    public function fetchUnit(string $id): ?array
    {
        if (!$this->isConfigured() || '' === trim($id)) {
            return null;
        }

        try {
            $response = $this->request('GET', '/products/'.rawurlencode($id), ['timeout' => 8]);
            $status = $response->getStatusCode();
            if (404 === $status) {
                return ['found' => false];
            }
            if ($status >= 300) {
                return null; // transient error → "couldn't fetch"
            }
            $item = $response->toArray(false);
            if (!is_array($item)) {
                return null;
            }

            $price = $this->extractPrice($item);

            return [
                'found' => true,
                'name' => is_scalar($item['name'] ?? null) ? (string) $item['name'] : '',
                'description' => is_scalar($item['description'] ?? null) ? (string) $item['description'] : '',
                'priceMinor' => $price['minor'],
                'currency' => $price['currency'],
                'taxInclusive' => $this->taxInclusive($item),
                'pricingMode' => $this->pricingMode($item),
                'sellable' => $this->isOneTimeProduct($item) && !$this->isPayWhatYouWant($item),
                'archived' => true === ($item['archived'] ?? null), // best-effort — Dodo may not report it here
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('Dodo fetch-product failed: {error}', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Verify ONE product by id — used at form SAVE to vet a MANUALLY-typed product id that wasn't in the
     * filtered list. READ-ONLY (never a checkout path). Delegates to fetchUnit so the two can't drift:
     *   - true  → sellable (a fixed-price one-time product, not pay-what-you-want),
     *   - false → NOT sellable (recurring / usage-based / pay-what-you-want) → the caller rejects the save,
     *   - null  → could NOT verify (unconfigured / not found / API error) → the caller warns but allows.
     * Faza 6 K2: implements MerchantOfRecordInterface::isSellableUnit().
     */
    public function isSellableUnit(string $id): ?bool
    {
        $info = $this->fetchUnit($id);
        if (null === $info || false === ($info['found'] ?? false)) {
            return null;
        }

        return $info['sellable'] ?? null;
    }

    /**
     * Faza 7: implements MerchantOfRecordInterface::listContainers() — Dodo "product collections". READ-ONLY
     * (GET /product-collections, non-archived, paged). Live-probed shape: envelope `items`, each collection
     * `{id, name, description, image, products_count, …}`. Empty on any failure → the import UI hides itself.
     *
     * @return list<array{id: string, name: string, description: ?string, productsCount: ?int}>
     */
    public function listContainers(): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $pageSize = 100;
        $maxPages = 5; // collections are few; a small cap (never loop unbounded)
        $out = [];
        try {
            for ($page = 0; $page < $maxPages; ++$page) {
                $response = $this->request('GET', '/product-collections', [
                    'query' => ['page_number' => $page, 'page_size' => $pageSize, 'archived' => 'false'],
                    'timeout' => 8,
                ]);
                if ($response->getStatusCode() >= 300) {
                    $this->logger->warning('Dodo list-collections returned {status}', ['status' => $response->getStatusCode()]);
                    break;
                }
                $data = $response->toArray(false);
                $items = $data['items'] ?? $data['data'] ?? (array_is_list($data) ? $data : []);
                if (!is_array($items) || [] === $items) {
                    break;
                }
                foreach ($items as $c) {
                    if (!is_array($c)) {
                        continue;
                    }
                    $id = $c['id'] ?? null;
                    if (!is_scalar($id) || '' === (string) $id) {
                        continue;
                    }
                    $out[] = [
                        'id' => (string) $id,
                        'name' => is_scalar($c['name'] ?? null) && '' !== (string) $c['name'] ? (string) $c['name'] : (string) $id,
                        'description' => is_scalar($c['description'] ?? null) && '' !== (string) $c['description'] ? (string) $c['description'] : null,
                        'productsCount' => is_numeric($c['products_count'] ?? null) ? (int) $c['products_count'] : null,
                    ];
                }
                if (count($items) < $pageSize) {
                    break; // last page
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Dodo list-collections failed: {error}', ['error' => $e->getMessage()]);

            return [];
        }

        return $out;
    }

    /**
     * Faza 7: implements MerchantOfRecordInterface::containerUnits() — the products of ONE Dodo collection,
     * for the "import from collection" builder action. READ-ONLY (GET /product-collections/{id}). Live-probed:
     * ONE call returns the collection meta + all products nested under `groups[].products[]` (each with
     * `product_id, name, description, price, currency, status, price_detail{type, pay_what_you_want}`), so no
     * N+1 and no grouping (the LIST endpoint carries NO collection id — proven). Iterates ALL groups; a product
     * that is inactive (`status===false`) / recurring / usage-based / pay-what-you-want is SKIPPED with a reason
     * (never imported). Reuses the same guards as listUnits so the two can't disagree.
     *
     * @return array{name: string, description: string, units: list<array{id: string, name: string, description: ?string, price: ?string, priceMinor: ?int, currency: ?string}>, skipped: list<array{name: string, reason: string}>}|null
     */
    public function containerUnits(string $containerId): ?array
    {
        if (!$this->isConfigured() || '' === trim($containerId)) {
            return null;
        }

        try {
            $response = $this->request('GET', '/product-collections/'.rawurlencode($containerId), ['timeout' => 8]);
            if ($response->getStatusCode() >= 300) {
                $this->logger->warning('Dodo collection {id} returned {status}', ['id' => $containerId, 'status' => $response->getStatusCode()]);

                return null; // not found / error → the endpoint maps this to a clear message
            }
            $data = $response->toArray(false);
            if (!is_array($data)) {
                return null;
            }

            $units = [];
            $skipped = [];
            $groups = $data['groups'] ?? [];
            if (is_array($groups)) {
                foreach ($groups as $group) { // iterate ALL groups (plan-switching groups; one-time = one group)
                    $products = is_array($group) ? ($group['products'] ?? []) : [];
                    if (!is_array($products)) {
                        continue;
                    }
                    foreach ($products as $p) {
                        if (!is_array($p)) {
                            continue;
                        }
                        $id = $p['product_id'] ?? $p['id'] ?? null;
                        if (!is_scalar($id) || '' === (string) $id) {
                            continue;
                        }
                        $name = is_scalar($p['name'] ?? null) && '' !== (string) $p['name'] ? (string) $p['name'] : (string) $id;

                        $reason = $this->unitSkipReason($p);
                        if (null !== $reason) {
                            $skipped[] = ['name' => $name, 'reason' => $reason];
                            continue;
                        }

                        $price = $this->extractPrice($p);
                        $units[] = [
                            'id' => (string) $id,
                            'name' => $name,
                            'description' => is_scalar($p['description'] ?? null) && '' !== (string) $p['description'] ? (string) $p['description'] : null,
                            'price' => $price['label'],
                            'priceMinor' => $price['minor'],
                            'currency' => $price['currency'],
                            'taxInclusive' => $this->taxInclusive($p),
                            'pricingMode' => $this->pricingMode($p),
                        ];
                    }
                }
            }

            return [
                'name' => is_scalar($data['name'] ?? null) ? (string) $data['name'] : '',
                'description' => is_scalar($data['description'] ?? null) ? (string) $data['description'] : '',
                'units' => $units,
                'skipped' => $skipped,
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('Dodo container-units failed: {error}', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Why a collection product can't be imported (Faza 7), or null if it's a sellable fixed-price one-time
     * unit. `inactive` = Dodo `status===false`; then reuse the same recurring/usage/PWYW guards as the picker.
     *
     * @param array<string, mixed> $p
     */
    private function unitSkipReason(array $p): ?string
    {
        if (false === ($p['status'] ?? null)) {
            return 'inactive';
        }
        if (!$this->isOneTimeProduct($p)) {
            $detail = $p['price_detail'] ?? null;
            $type = is_array($detail) ? ($detail['type'] ?? null) : null;
            if ('usage_based_price' === $type || (is_array($detail) && isset($detail['usage_based_price']))) {
                return 'usage_based';
            }

            return 'recurring';
        }
        if ($this->isPayWhatYouWant($p)) {
            return 'pay_what_you_want';
        }

        return null;
    }

    /**
     * Best-effort structured price from a product item: minor units + currency + a display label.
     * ✅ Live-proven (probed 2026-07-15): LIST returns a scalar `price` + a `price_detail` object; DETAIL
     * (GET /products/{id}) returns a `price` OBJECT ({price, currency, …}). Both shapes are handled below.
     * MULTI-CURRENCY / localized pricing is NOT guessed — a list-shaped price returns all null, and an
     * ambiguous currency is left null (the prefill then leaves that field untouched: better empty than wrong).
     *
     * @return array{minor: ?int, currency: ?string, label: ?string}
     */
    private function extractPrice(array $item): array
    {
        $price = $item['price'] ?? null;
        $amount = null;
        $currency = null;

        if (is_array($price)) {
            // A list = multi-currency / multiple prices → don't guess anything.
            if (array_is_list($price)) {
                return ['minor' => null, 'currency' => null, 'label' => null];
            }
            $amount = $price['price'] ?? $price['amount'] ?? null;
            $currency = $price['currency'] ?? null;
        } elseif (is_numeric($price)) {
            $amount = $price;
            $currency = $item['currency'] ?? null;
        }

        $minor = is_numeric($amount) ? (int) $amount : null;
        $currency = is_string($currency) && '' !== $currency ? strtoupper($currency) : null;

        if (null === $minor) {
            return ['minor' => null, 'currency' => $currency, 'label' => null];
        }

        $label = number_format($minor / 100, 2);
        $label = null !== $currency ? $label.' '.$currency : $label;

        return ['minor' => $minor, 'currency' => $currency, 'label' => $label];
    }

    // --- internals -------------------------------------------------------------------------------

    /**
     * Standard Webhooks verification: HMAC-SHA256 over "{webhook-id}.{webhook-timestamp}.{rawBody}",
     * base64, compared against each `v1,<base64>` entry in the (space-separated) webhook-signature
     * header. Plus a timestamp tolerance (replay guard).
     *
     * @param array<string, string> $headers lowercased header bag
     *
     * @throws \RuntimeException when the signature can't be verified
     */
    private function verifyWebhook(string $rawBody, array $headers): void
    {
        $id = $headers['webhook-id'] ?? '';
        $timestamp = $headers['webhook-timestamp'] ?? '';
        $signature = $headers['webhook-signature'] ?? '';

        if ('' === $id || '' === $timestamp || '' === $signature) {
            throw new \RuntimeException('Dodo webhook is missing Standard Webhooks headers.');
        }

        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::WEBHOOK_TOLERANCE) {
            throw new \RuntimeException('Dodo webhook timestamp is outside the tolerance window.');
        }

        $secret = $this->webhookSecretKey();
        if ('' === $secret) {
            throw new \RuntimeException('Dodo webhook secret is not configured.');
        }

        $signed = $id.'.'.$timestamp.'.'.$rawBody;
        $expected = base64_encode(hash_hmac('sha256', $signed, $secret, true));

        // webhook-signature can carry several space-separated "v1,<sig>" entries — accept any match.
        foreach (explode(' ', $signature) as $part) {
            $sig = str_contains($part, ',') ? substr($part, strpos($part, ',') + 1) : $part;
            if ('' !== $sig && hash_equals($expected, $sig)) {
                return;
            }
        }

        throw new \RuntimeException('Dodo webhook signature verification failed.');
    }

    /**
     * The raw HMAC key. Standard Webhooks secrets are often "whsec_<base64>" → strip the prefix and
     * base64-decode to the raw bytes. ✅ Live-proven (Faza 6–8): real Dodo webhooks verified with this whsec_/base64 handling.
     */
    private function webhookSecretKey(): string
    {
        $secret = $this->webhookSecret();
        if (str_starts_with($secret, 'whsec_')) {
            $decoded = base64_decode(substr($secret, 6), true);
            if (false !== $decoded) {
                return $decoded;
            }
        }

        return $secret;
    }

    private function apiKey(): string
    {
        return ((string) $this->settings->get('dodo_api_key')) ?: $this->apiKeyEnv;
    }

    private function webhookSecret(): string
    {
        return ((string) $this->settings->get('dodo_webhook_secret')) ?: $this->webhookSecretEnv;
    }

    private function mode(): string
    {
        return ((string) $this->settings->get('dodo_mode')) ?: ($this->modeEnv ?: 'test');
    }

    private function baseUrl(): string
    {
        return 'live' === $this->mode() ? self::HOST_LIVE : self::HOST_TEST;
    }

    /** Authenticated request (Bearer API key); returns the raw response (caller checks status). */
    private function request(string $method, string $path, array $options = []): ResponseInterface
    {
        return $this->http->request($method, $this->baseUrl().$path, [
            'auth_bearer' => $this->apiKey(),
        ] + $options);
    }

    /**
     * Authenticated JSON request that throws on a non-2xx and returns the decoded body.
     *
     * @return array<string, mixed>
     */
    private function json(string $method, string $path, array $json): array
    {
        $response = $this->request($method, $path, ['json' => $json]);
        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException(\sprintf('Dodo %s %s failed: %s', $method, $path, $response->getContent(false)));
        }

        return $response->toArray(false);
    }
}
