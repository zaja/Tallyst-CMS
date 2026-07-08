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
 * ⚠ VERIFY (Dodo API reference / a real test account) — every path/field below marked "VERIFY" is an
 * assumption from the brief, NOT confirmed against the live API. Correct before go-live:
 *   - the checkout / refund endpoints + request shapes,
 *   - the amount/currency fields for dynamic pricing over a single product,
 *   - the refund event type,
 *   - that payment.succeeded carries metadata.order_id back (correlation key),
 *   - the webhook secret encoding (raw vs whsec_/base64),
 *   - whether the hosted checkout auto-captures (finalizeReturn no-op) or needs a capture call.
 */
class DodoProcessor implements PaymentProcessorInterface, MerchantOfRecordInterface
{
    // ⚠ VERIFY host scheme — the brief gives test.dodopayments.com / live.dodopayments.com.
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
        // ⚠ VERIFY the exact refund + entitlement event types (assumed 'refund.succeeded' /
        // 'entitlement_grant.created').
        return ['payment.succeeded', 'payment.failed', 'refund.succeeded', 'entitlement_grant.created'];
    }

    /** No reliable per-transaction deep-link documented for Dodo yet — honest null (no dashboard button). */
    public function dashboardUrl(Order $order): ?string
    {
        return null;
    }

    public function createCheckout(Order $order, string $successUrl, string $cancelUrl): string
    {
        // v1.7.0 = FIXED price only. Variant forms are not supported for MoR yet (the buyer-facing
        // guard lives in FormSubmitController; this is a defensive backstop).
        if ($order->getForm()?->hasVariants()) {
            throw new \RuntimeException('Dodo (Merchant-of-Record) supports only a fixed price in v1.7.0; this form uses variants.');
        }

        // Per-form Dodo product (set via SQL for now; the edit-form UI is Phase 3). The buyer-facing
        // guard lives in FormSubmitController; this is a defensive backstop (never a dead checkout).
        $productId = (string) $order->getForm()?->getDodoProductId();
        if ('' === $productId) {
            throw new \RuntimeException('This form has no linked Dodo product.');
        }

        // ⚠ VERIFY endpoint + shape. Brief: checkoutSessions.create(product_cart, metadata) → returns
        // a hosted checkout_url + a session id. Dynamic pricing carries the per-order amount.
        $data = $this->json('POST', '/checkouts', [
            'product_cart' => [[
                'product_id' => $productId,
                'quantity' => 1,
                // ⚠ VERIFY the dynamic-pricing amount field + unit (assumed minor units, like Stripe).
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
     * stays the sole source of truth for `paid`. ⚠ VERIFY: if Dodo needs a capture-on-return call
     * (like PayPal), implement it here (idempotent, MUST NOT set paid).
     */
    public function finalizeReturn(Order $order): void
    {
    }

    public function parseSignedWebhook(string $payload, array $headers): WebhookResult
    {
        $this->verifyWebhook($payload, $headers);

        $event = json_decode($payload, true) ?: [];
        // ⚠ VERIFY event key name (brief payload uses `type`; some providers use `event_type`).
        $type = (string) ($event['type'] ?? $event['event_type'] ?? '');
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];

        if ('payment.failed' === $type) {
            $this->logger->warning('Dodo payment.failed', ['payment_id' => $data['payment_id'] ?? null]);
        }

        $isPaid = 'payment.succeeded' === $type;
        // ⚠ VERIFY refund + entitlement event types.
        $isRefund = 'refund.succeeded' === $type;
        $isEntitlement = 'entitlement_grant.created' === $type;

        // Correlation: metadata.order_id we sent at checkout, echoed back by Dodo. Look in data.metadata
        // first, then a top-level metadata, then a top-level order_id — whichever Dodo uses. ⚠ VERIFY.
        // (Entitlement events carry empty metadata → orderId stays null; they correlate by payment_id.)
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata']
            : (is_array($event['metadata'] ?? null) ? $event['metadata'] : []);
        $orderId = $metadata['order_id'] ?? $event['metadata']['order_id'] ?? null;

        // payment_id is the bridge to the order for BOTH paid and entitlement events. ⚠ VERIFY path.
        $paymentId = $data['payment_id'] ?? null;
        $email = $data['customer']['email'] ?? null;

        // Phase 2 passive capture. Payment fields (customer/finance) live on payment.succeeded; the
        // licence key lives on entitlement_grant.created (data.license_key.key). ⚠ VERIFY every path.
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

        // ⚠ VERIFY refund endpoint + body. Full refund (v1 = full only, like Stripe/PayPal).
        $response = $this->request('POST', '/refunds', ['json' => ['payment_id' => $paymentId]]);
        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException('Dodo refund failed: '.$response->getContent(false));
        }
    }

    /**
     * List the Dodo products in the ACTIVE mode (test/live), for the per-form product picker. READ-ONLY
     * — never writes to Dodo. Returns a flat list of ['id' => string, 'name' => string, 'price' => ?string]
     * (price is a best-effort display label, may be null). Returns [] when the provider isn't configured
     * (no HTTP) or on ANY error — the caller falls back to a manual product-id input, never a hard failure.
     *
     * A short timeout + a page cap keep the edit-form render responsive.
     *
     * ⚠ VERIFY (no web access) — assumptions from the brief, correct against the Dodo API reference:
     *   - endpoint GET /products, Bearer auth (same as the rest of this processor),
     *   - pagination query keys (assumed page_number + page_size) + the response items key (assumed `items`),
     *   - the product id / name / price field names in each item.
     *
     * @return list<array{id: string, name: string, price: ?string}>
     */
    public function listProducts(): array
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
                    'query' => ['page_number' => $page, 'page_size' => $pageSize],
                    'timeout' => 8,
                ]);
                if ($response->getStatusCode() >= 300) {
                    // Non-2xx → give up and let the caller fall back (don't throw into the form render).
                    $this->logger->warning('Dodo list-products returned {status}', ['status' => $response->getStatusCode()]);
                    break;
                }

                $data = $response->toArray(false);
                // ⚠ VERIFY items key (assumed `items`; some APIs return a bare array or `data`).
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
                    $products[] = [
                        'id' => (string) $id,
                        'name' => is_scalar($item['name'] ?? null) && '' !== (string) $item['name'] ? (string) $item['name'] : (string) $id,
                        'price' => $this->productPriceLabel($item),
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

    /** Best-effort human price label from a product item, or null when the shape is unknown. */
    private function productPriceLabel(array $item): ?string
    {
        // ⚠ VERIFY price shape. Try a nested {price: {price: minor, currency}} then a flat price/currency.
        $price = $item['price'] ?? null;
        $amount = is_array($price) ? ($price['price'] ?? $price['amount'] ?? null) : (is_numeric($price) ? $price : null);
        $currency = is_array($price) ? ($price['currency'] ?? null) : ($item['currency'] ?? null);

        if (!is_numeric($amount)) {
            return null;
        }

        $label = number_format(((int) $amount) / 100, 2);

        return is_string($currency) && '' !== $currency ? $label.' '.strtoupper($currency) : $label;
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
     * base64-decode to the raw bytes. ⚠ VERIFY Dodo's encoding (raw string vs whsec_/base64).
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
