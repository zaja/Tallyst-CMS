<?php

namespace App\Tests\FormBuilder;

use App\Settings\SettingsManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tallyst\FormBuilder\Payment\PayPalProcessor;

/**
 * Offline (local RSA cert) PayPal webhook verification — no network, no token/scope. Replaces the
 * verify-webhook-signature API (which 403s because this app's token lacks the webhooks scope).
 */
class PayPalWebhookVerifyTest extends TestCase
{
    private const WEBHOOK_ID = 'WH-TEST-ID';
    private const PAYLOAD = '{"event_type":"PAYMENT.CAPTURE.COMPLETED","resource":{"id":"CAP-1","supplementary_data":{"related_ids":{"order_id":"ORD-1"}}}}';

    /** @var \OpenSSLAsymmetricKey */
    private $key;
    private string $publicPem;

    protected function setUp(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        if (false === $key) {
            self::markTestSkipped('openssl keypair generation unavailable in this environment.');
        }
        $this->key = $key;
        $this->publicPem = openssl_pkey_get_details($key)['key'];
    }

    private function processor(): PayPalProcessor
    {
        $settings = $this->createStub(SettingsManager::class);
        $settings->method('get')->willReturnCallback(static fn (string $k) => 'paypal_webhook_id' === $k ? self::WEBHOOK_ID : null);

        // The mocked cert endpoint returns our generated public key (openssl_pkey_get_public reads it).
        $http = new MockHttpClient(fn (string $method, string $url): MockResponse => new MockResponse($this->publicPem));

        return new PayPalProcessor($http, $settings, new ArrayAdapter(), new NullLogger(), '', '', '', 'sandbox');
    }

    private function sign(string $message): string
    {
        openssl_sign($message, $signature, $this->key, \OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $sig, string $certUrl = 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-x'): array
    {
        return [
            'paypal-transmission-id' => 'tid-1',
            'paypal-transmission-time' => '2026-06-22T21:31:07Z',
            'paypal-transmission-sig' => $sig,
            'paypal-cert-url' => $certUrl,
            'paypal-auth-algo' => 'SHA256withRSA',
        ];
    }

    private function validSig(): string
    {
        $message = 'tid-1|2026-06-22T21:31:07Z|'.self::WEBHOOK_ID.'|'.sprintf('%u', crc32(self::PAYLOAD));

        return $this->sign($message);
    }

    public function testValidSignaturePassesAndMaps(): void
    {
        $result = $this->processor()->parseSignedWebhook(self::PAYLOAD, $this->headers($this->validSig()));

        self::assertTrue($result->isPaid);
        self::assertSame('ORD-1', $result->sessionId);
        self::assertSame('CAP-1', $result->paymentIntentId);
    }

    public function testTamperedSignatureThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        // Signature over a DIFFERENT message → won't match the real body.
        $badSig = $this->sign('tid-1|2026-06-22T21:31:07Z|'.self::WEBHOOK_ID.'|999999');
        $this->processor()->parseSignedWebhook(self::PAYLOAD, $this->headers($badSig));
    }

    public function testNonPayPalCertUrlRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->processor()->parseSignedWebhook(self::PAYLOAD, $this->headers($this->validSig(), 'https://evil.com/cert'));
    }

    public function testMissingHeadersThrow(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->processor()->parseSignedWebhook(self::PAYLOAD, []);
    }
}
