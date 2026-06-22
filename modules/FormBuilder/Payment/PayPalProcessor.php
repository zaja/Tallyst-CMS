<?php

namespace Tallyst\FormBuilder\Payment;

use App\Settings\SettingsManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Tallyst\FormBuilder\Entity\Order;

/**
 * PayPal via the Orders v2 REST API (direct HttpClient — the official PHP SDK is archived and the
 * new server SDK is too partial). Every PayPal-specific concern (OAuth, the capture-on-return step
 * Stripe doesn't have, the verify-webhook-signature API call, sandbox/live base host, explicit mode)
 * is contained HERE; the rest of the app sees only PaymentProcessorInterface + WebhookResult.
 *
 * Keys come from Postavke → PayPal (encrypted) with an env fallback. Mode is EXPLICIT (sandbox/live)
 * — PayPal credentials carry no test/live marker, unlike Stripe's sk_ prefix.
 */
class PayPalProcessor implements PaymentProcessorInterface
{
    private const HOST_LIVE = 'https://api-m.paypal.com';
    private const HOST_SANDBOX = 'https://api-m.sandbox.paypal.com';

    private ?string $accessToken = null;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly SettingsManager $settings,
        #[Autowire('%env(PAYPAL_CLIENT_ID)%')]
        private readonly string $clientIdEnv,
        #[Autowire('%env(PAYPAL_CLIENT_SECRET)%')]
        private readonly string $clientSecretEnv,
        #[Autowire('%env(PAYPAL_WEBHOOK_ID)%')]
        private readonly string $webhookIdEnv,
        #[Autowire('%env(PAYPAL_MODE)%')]
        private readonly string $modeEnv,
    ) {
    }

    public function getName(): string
    {
        return 'paypal';
    }

    public function isConfigured(): bool
    {
        return '' !== $this->clientId() && '' !== $this->clientSecret();
    }

    public function getMode(): string
    {
        if (!$this->isConfigured()) {
            return 'unconfigured';
        }

        return 'live' === $this->mode() ? 'live' : 'test';
    }

    public function getWebhookEvents(): array
    {
        return ['PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.REFUNDED'];
    }

    public function createCheckout(Order $order, string $successUrl, string $cancelUrl): string
    {
        $data = $this->json('POST', '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'custom_id' => (string) $order->getId(),
                'amount' => [
                    'currency_code' => strtoupper((string) $order->getCurrency()),
                    'value' => $this->minorToDecimal($order->getAmountMinor()),
                ],
            ]],
            'payment_source' => ['paypal' => ['experience_context' => [
                'return_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'user_action' => 'PAY_NOW',
            ]]],
        ]);

        $order->setProviderSessionId((string) ($data['id'] ?? ''));

        foreach ($data['links'] ?? [] as $link) {
            if (in_array($link['rel'] ?? '', ['payer-action', 'approve'], true)) {
                return (string) $link['href'];
            }
        }

        throw new \RuntimeException('PayPal create-order returned no approval link.');
    }

    public function finalizeReturn(Order $order): void
    {
        // Idempotent: the webhook may have already marked it paid (return refresh / race).
        if ($order->isPaid()) {
            return;
        }

        $orderId = (string) $order->getProviderSessionId();
        if ('' === $orderId) {
            return;
        }

        $response = $this->request('POST', '/v2/checkout/orders/'.$orderId.'/capture', ['json' => new \stdClass()]);
        $status = $response->getStatusCode();
        $body = $response->getContent(false);

        if ($status >= 300) {
            // Already captured (e.g. webhook/return race) is a no-op, not an error.
            if (str_contains($body, 'ORDER_ALREADY_CAPTURED')) {
                return;
            }

            throw new \RuntimeException('PayPal capture failed: '.$body);
        }

        $data = json_decode($body, true) ?: [];
        $capture = $data['purchase_units'][0]['payments']['captures'][0] ?? null;
        if (is_array($capture) && isset($capture['id'])) {
            // Store the capture id now so a refund works even before the webhook lands.
            $order->setProviderPaymentIntentId((string) $capture['id']);
        }

        // Fill the buyer e-mail from the capture (PayPal's webhook doesn't carry it reliably).
        $email = $data['payment_source']['paypal']['email_address']
            ?? $data['payer']['email_address']
            ?? null;
        if (is_string($email) && '' !== $email && (null === $order->getCustomerEmail() || '' === $order->getCustomerEmail())) {
            $order->setCustomerEmail($email);
        }

        // Does NOT set paid — the PAYMENT.CAPTURE.COMPLETED webhook stays the sole source of truth.
    }

    public function parseSignedWebhook(string $payload, array $headers): WebhookResult
    {
        $event = json_decode($payload, true) ?: [];

        $verify = $this->json('POST', '/v1/notifications/verify-webhook-signature', [
            'auth_algo' => $headers['paypal-auth-algo'] ?? '',
            'cert_url' => $headers['paypal-cert-url'] ?? '',
            'transmission_id' => $headers['paypal-transmission-id'] ?? '',
            'transmission_sig' => $headers['paypal-transmission-sig'] ?? '',
            'transmission_time' => $headers['paypal-transmission-time'] ?? '',
            'webhook_id' => $this->webhookId(),
            'webhook_event' => $event,
        ]);

        if ('SUCCESS' !== ($verify['verification_status'] ?? null)) {
            throw new \RuntimeException('PayPal webhook verification failed.');
        }

        $type = (string) ($event['event_type'] ?? '');
        $resource = $event['resource'] ?? [];

        $isPaid = 'PAYMENT.CAPTURE.COMPLETED' === $type;
        $isRefund = 'PAYMENT.CAPTURE.REFUNDED' === $type;

        // paid: match the order by the PayPal order id; store the capture id for refunds.
        // refund: match by the capture id (carried in the refund's rel="up" link).
        $sessionId = null;
        $paymentIntentId = null;
        if ($isPaid) {
            $sessionId = $resource['supplementary_data']['related_ids']['order_id'] ?? null;
            $paymentIntentId = $resource['id'] ?? null;
        } elseif ($isRefund) {
            $paymentIntentId = $this->captureIdFromRefund($resource);
        }

        return new WebhookResult(
            eventType: $type,
            sessionId: is_string($sessionId) ? $sessionId : null,
            paymentIntentId: is_string($paymentIntentId) ? $paymentIntentId : null,
            isPaid: $isPaid,
            customerEmail: null,
            isRefund: $isRefund,
        );
    }

    public function refund(Order $order): void
    {
        $captureId = (string) $order->getProviderPaymentIntentId();
        if ('' === $captureId) {
            throw new \RuntimeException('Order has no PayPal capture to refund.');
        }

        // Empty body = full refund.
        $response = $this->request('POST', '/v2/payments/captures/'.$captureId.'/refund', ['json' => new \stdClass()]);
        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException('PayPal refund failed: '.$response->getContent(false));
        }
    }

    // --- internals -------------------------------------------------------------------------------

    private function clientId(): string
    {
        return ((string) $this->settings->get('paypal_client_id')) ?: $this->clientIdEnv;
    }

    private function clientSecret(): string
    {
        return ((string) $this->settings->get('paypal_client_secret')) ?: $this->clientSecretEnv;
    }

    private function webhookId(): string
    {
        return ((string) $this->settings->get('paypal_webhook_id')) ?: $this->webhookIdEnv;
    }

    private function mode(): string
    {
        return ((string) $this->settings->get('paypal_mode')) ?: ($this->modeEnv ?: 'sandbox');
    }

    private function baseUrl(): string
    {
        return 'live' === $this->mode() ? self::HOST_LIVE : self::HOST_SANDBOX;
    }

    /** Amount in minor units → "X.XX" via integer math (no float rounding). 2-decimal currencies. */
    private function minorToDecimal(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', \STR_PAD_LEFT);
    }

    private function captureIdFromRefund(array $resource): ?string
    {
        foreach ($resource['links'] ?? [] as $link) {
            if ('up' === ($link['rel'] ?? '') && isset($link['href'])) {
                $parts = explode('/', rtrim((string) $link['href'], '/'));

                return end($parts) ?: null;
            }
        }

        return null;
    }

    private function token(): string
    {
        if (null !== $this->accessToken) {
            return $this->accessToken;
        }

        $data = $this->http->request('POST', $this->baseUrl().'/v1/oauth2/token', [
            'auth_basic' => [$this->clientId(), $this->clientSecret()],
            'body' => ['grant_type' => 'client_credentials'],
        ])->toArray();

        return $this->accessToken = (string) ($data['access_token'] ?? '');
    }

    /** Authenticated request (bearer token); returns the raw response (caller checks status). */
    private function request(string $method, string $path, array $options = []): ResponseInterface
    {
        return $this->http->request($method, $this->baseUrl().$path, [
            'auth_bearer' => $this->token(),
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
            throw new \RuntimeException(\sprintf('PayPal %s %s failed: %s', $method, $path, $response->getContent(false)));
        }

        return $response->toArray(false);
    }
}
