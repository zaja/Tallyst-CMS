<?php

namespace App\Tests\FormBuilder;

use App\Settings\SettingsManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tallyst\FormBuilder\Payment\DodoProcessor;
use Tallyst\FormBuilder\Payment\PayPalProcessor;
use Tallyst\FormBuilder\Payment\StripeProcessor;

/**
 * Which events each provider treats as "this checkout will not be paid".
 *
 * ⚠ THE FLAG IS NOT THE OPPOSITE OF isPaid, AND GETTING THAT WRONG IS THE RISK HERE. Most unpaid
 * checkouts produce no event at all — somebody who closes the window tells nobody — so this only
 * ever means "the provider volunteered that it failed". The 24-hour deadline is the floor that
 * catches everything else. Treating any non-payment event as a failure would close checkouts that
 * are still in progress, which is why each provider's list is pinned here individually.
 */
class ProviderFailureEventTest extends TestCase
{
    private const string STRIPE_SECRET = 'whsec_test_secret';
    private const string DODO_SECRET = 'whsec_dodo_secret';

    // ── Stripe ──────────────────────────────────────────────────────────────

    private function stripe(): StripeProcessor
    {
        $settings = $this->createStub(SettingsManager::class);
        $settings->method('get')->willReturn(null);

        return new StripeProcessor($settings, 'sk_test_key', self::STRIPE_SECRET);
    }

    private function stripeEvent(string $type): \Tallyst\FormBuilder\Payment\WebhookResult
    {
        $payload = json_encode([
            'id' => 'evt_1P9x',
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => [
                'id' => 'cs_test_a1b2c3',
                'object' => 'checkout.session',
                'payment_status' => 'unpaid',
                'status' => 'expired',
                'customer_details' => ['email' => 'buyer@example.com'],
            ]],
        ], \JSON_THROW_ON_ERROR);

        $ts = time();

        return $this->stripe()->parseSignedWebhook($payload, [
            'stripe-signature' => \sprintf('t=%d,v1=%s', $ts, hash_hmac('sha256', $ts.'.'.$payload, self::STRIPE_SECRET)),
        ]);
    }

    /**
     * ⚠ THERE IS NO "CARD DECLINED" EVENT AT STRIPE, so these two are the whole story: a session
     * Stripe has given up on, and a delayed method (SEPA and friends) that came back refused.
     */
    public function testStripeReportsAnExpiredSessionAsAFailure(): void
    {
        self::assertTrue($this->stripeEvent('checkout.session.expired')->isFailed);
    }

    public function testStripeReportsADelayedPaymentFailureAsAFailure(): void
    {
        self::assertTrue($this->stripeEvent('checkout.session.async_payment_failed')->isFailed);
    }

    /** ⚠ A completed session is never a failure, whatever else it says. */
    public function testStripeDoesNotCallACompletedSessionAFailure(): void
    {
        self::assertFalse($this->stripeEvent('checkout.session.completed')->isFailed);
    }

    /** The owner is told to subscribe them, or the events never arrive at all. */
    public function testStripeAdvertisesTheFailureEventsToTheOwner(): void
    {
        $events = $this->stripe()->getWebhookEvents();

        self::assertContains('checkout.session.expired', $events);
        self::assertContains('checkout.session.async_payment_failed', $events);
    }

    // ── PayPal ──────────────────────────────────────────────────────────────

    /** @var \OpenSSLAsymmetricKey */
    private $key;
    private string $publicPem;

    private function paypal(): PayPalProcessor
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        if (false === $key) {
            self::markTestSkipped('openssl keypair generation unavailable in this environment.');
        }
        $this->key = $key;
        $this->publicPem = openssl_pkey_get_details($key)['key'];

        $settings = $this->createStub(SettingsManager::class);
        $settings->method('get')->willReturnCallback(static fn (string $k): ?string => 'paypal_webhook_id' === $k ? 'WH-TEST-ID' : null);

        return new PayPalProcessor(
            new MockHttpClient(fn (): MockResponse => new MockResponse($this->publicPem)),
            $settings,
            new ArrayAdapter(),
            new NullLogger(),
            '', '', '', 'sandbox',
        );
    }

    private function paypalEvent(string $type): \Tallyst\FormBuilder\Payment\WebhookResult
    {
        $processor = $this->paypal();
        $payload = json_encode([
            'event_type' => $type,
            'resource' => [
                'id' => 'CAP-1',
                'status_details' => ['reason' => 'INSTRUMENT_DECLINED'],
                'supplementary_data' => ['related_ids' => ['order_id' => 'ORD-1']],
            ],
        ], \JSON_THROW_ON_ERROR);

        $message = 'tid-1|2026-08-15T10:00:00Z|WH-TEST-ID|'.\sprintf('%u', crc32($payload));
        openssl_sign($message, $signature, $this->key, \OPENSSL_ALGO_SHA256);

        return $processor->parseSignedWebhook($payload, [
            'paypal-transmission-id' => 'tid-1',
            'paypal-transmission-time' => '2026-08-15T10:00:00Z',
            'paypal-transmission-sig' => base64_encode($signature),
            'paypal-cert-url' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-x',
            'paypal-auth-algo' => 'SHA256withRSA',
        ]);
    }

    public function testPayPalReportsADeniedCaptureAsAFailure(): void
    {
        $result = $this->paypalEvent('PAYMENT.CAPTURE.DENIED');

        self::assertTrue($result->isFailed);
        self::assertSame('ORD-1', $result->sessionId, 'a denied capture must still find its order');
        self::assertSame('INSTRUMENT_DECLINED', $result->failureReason);
    }

    /**
     * ⚠ PENDING IS NOT A FAILURE, and this is the case most likely to be "simplified" wrongly.
     * A pending capture is still on its way and routinely settles; closing the checkout on it would
     * declare a purchase dead while the money is in flight.
     */
    public function testPayPalDoesNotTreatAPendingCaptureAsAFailure(): void
    {
        self::assertFalse($this->paypalEvent('PAYMENT.CAPTURE.PENDING')->isFailed);
    }

    public function testPayPalAdvertisesTheDeniedEventToTheOwner(): void
    {
        self::assertContains('PAYMENT.CAPTURE.DENIED', $this->paypal()->getWebhookEvents());
    }

    // ── Dodo ────────────────────────────────────────────────────────────────

    private function dodoEvent(string $type): \Tallyst\FormBuilder\Payment\WebhookResult
    {
        $processor = new DodoProcessor(
            new MockHttpClient(),
            $this->createStub(SettingsManager::class),
            new NullLogger(),
            'dodo_test_key',
            self::DODO_SECRET,
            'test',
        );

        $payload = json_encode([
            'type' => $type,
            'data' => [
                'payment_id' => 'pay_9f2c',
                'metadata' => ['order_id' => '4242'],
                'error_message' => 'Card was declined',
                'customer' => ['email' => 'buyer@example.com'],
            ],
        ], \JSON_THROW_ON_ERROR);

        $id = 'msg_test';
        $ts = (string) time();

        return $processor->parseSignedWebhook($payload, [
            'webhook-id' => $id,
            'webhook-timestamp' => $ts,
            'webhook-signature' => 'v1,'.base64_encode(hash_hmac('sha256', $id.'.'.$ts.'.'.$payload, self::DODO_SECRET, true)),
        ]);
    }

    /**
     * ⚠ Dodo has volunteered this since Faza 8 and it was LOGGED ONLY until the order state existed
     * to record it — the event was arriving and being thrown away.
     */
    public function testDodoReportsAFailedPaymentAsAFailure(): void
    {
        $result = $this->dodoEvent('payment.failed');

        self::assertTrue($result->isFailed);
        self::assertSame('4242', $result->orderId, 'it must still correlate to our order');
        self::assertSame('Card was declined', $result->failureReason);
    }

    public function testDodoDoesNotCallASucceededPaymentAFailure(): void
    {
        $result = $this->dodoEvent('payment.succeeded');

        self::assertFalse($result->isFailed);
        self::assertTrue($result->isPaid);
    }
}
