<?php

namespace App\Tests\FormBuilder;

use App\Settings\SettingsManager;
use PHPUnit\Framework\TestCase;
use Tallyst\FormBuilder\Payment\StripeProcessor;

/**
 * What we read OUT of a Stripe webhook body — the buyer's e-mail above all.
 *
 * ⚠ WHY THIS EXISTS. Nothing in this suite used to read a real provider payload, so a parser could
 * stop finding the buyer and every test would stay green. That is not theoretical: on 2026-08-10 a
 * mechanical rename did exactly that to the Dodo parser, and the whole suite passed. These tests are
 * the control that was missing — one per provider, over the shape the provider actually sends.
 *
 * ⚠ SOURCE OF THE SHAPE. This fixture comes from Stripe's DOCUMENTED API (Event → Checkout Session
 * / Charge), NOT from a response recorded on this system — unlike the Dodo fixture, which was taken
 * from live traffic. The fields below are the load-bearing ones, and they are what a live purchase
 * should be checked against the first time one runs through Stripe here:
 *
 *   data.object.customer_details.email  — the buyer, first choice
 *   data.object.customer_email          — the buyer, fallback
 *   data.object.id                      — the Checkout Session, how the order is found
 *   data.object.payment_intent          — kept so a later refund has something to act on
 *   data.object.payment_status          — must be "paid" before we believe the money moved
 *   charge.refunded: amount / amount_refunded — a refund counts only when it is FULL
 *
 * Everything else in the fixture is context: present because Stripe sends it, unread by us.
 */
class StripeWebhookPayloadTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    private function stripe(): StripeProcessor
    {
        $settings = $this->createStub(SettingsManager::class);
        $settings->method('get')->willReturn(null);

        return new StripeProcessor($settings, 'sk_test_key', self::SECRET);
    }

    /**
     * Signs a body the way Stripe does (`t=<ts>,v1=<hmac of "ts.payload">`), so the parser is
     * reached at all. Verification is the SDK's own — we only have to satisfy it honestly.
     */
    private function parse(array $event): \Tallyst\FormBuilder\Payment\WebhookResult
    {
        $payload = json_encode($event, \JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, self::SECRET);

        return $this->stripe()->parseSignedWebhook($payload, [
            'stripe-signature' => \sprintf('t=%d,v1=%s', $timestamp, $signature),
        ]);
    }

    /** A real checkout.session.completed body, as Stripe sends it. */
    private function checkoutCompleted(): array
    {
        return [
            'id' => 'evt_1P9xTest',
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'api_version' => '2024-06-20',
            'created' => 1770000000,
            'data' => [
                'object' => [
                    'id' => 'cs_test_a1b2c3',
                    'object' => 'checkout.session',
                    'amount_total' => 3625,
                    'currency' => 'eur',
                    'customer' => 'cus_QabcDEF',
                    'customer_details' => [
                        'email' => 'buyer@example.com',
                        'name' => 'Pero Perić',
                        'address' => [
                            'country' => 'HR',
                            'city' => 'Zagreb',
                            'line1' => 'Ilica 1',
                            'line2' => null,
                            'postal_code' => '10000',
                            'state' => null,
                        ],
                        'phone' => null,
                        'tax_exempt' => 'none',
                        'tax_ids' => [],
                    ],
                    'customer_email' => null,
                    'mode' => 'payment',
                    'payment_intent' => 'pi_3P9xTest',
                    'payment_status' => 'paid',
                    'status' => 'complete',
                    'metadata' => ['order_id' => '4242'],
                ],
            ],
        ];
    }

    /**
     * ⚠ THE ONE THAT MATTERS. The buyer's e-mail is the only way the confirmation reaches anybody.
     * If this key stops being read, the sale still records and nothing throws — the customer simply
     * never hears from the shop.
     */
    public function testTheBuyerIsReadFromStripesCustomerDetails(): void
    {
        $result = $this->parse($this->checkoutCompleted());

        self::assertSame('buyer@example.com', $result->customerEmail, "Stripe's data.object.customer_details.email");
    }

    /**
     * Stripe fills `customer_email` instead when the address was known before checkout (a returning
     * customer, or one passed in at session creation) and `customer_details` is absent.
     */
    public function testTheBuyerFallsBackToCustomerEmail(): void
    {
        $event = $this->checkoutCompleted();
        unset($event['data']['object']['customer_details']);
        $event['data']['object']['customer_email'] = 'returning@example.com';

        self::assertSame('returning@example.com', $this->parse($event)->customerEmail);
    }

    public function testThePaymentIsCorrelatedAndRecorded(): void
    {
        $result = $this->parse($this->checkoutCompleted());

        self::assertTrue($result->isPaid);
        self::assertSame('cs_test_a1b2c3', $result->sessionId, 'the session id is how the order is found');
        self::assertSame('pi_3P9xTest', $result->paymentIntentId, 'kept so a later refund has something to act on');
        self::assertSame('checkout.session.completed', $result->eventType);
        self::assertFalse($result->isRefund);
    }

    /**
     * ⚠ A completed session is NOT proof of payment on its own — an unpaid one arrives too (delayed
     * payment methods). Believing it would mark an order paid before any money moved.
     */
    public function testACompletedSessionThatIsNotPaidDoesNotCount(): void
    {
        $event = $this->checkoutCompleted();
        $event['data']['object']['payment_status'] = 'unpaid';

        self::assertFalse($this->parse($event)->isPaid);
    }

    /** A session with no buyer details must degrade to null, not blow up mid-webhook. */
    public function testAMissingBuyerIsSurvivable(): void
    {
        $event = $this->checkoutCompleted();
        unset($event['data']['object']['customer_details'], $event['data']['object']['customer_email']);

        $result = $this->parse($event);

        self::assertTrue($result->isPaid, 'the payment is still a payment');
        self::assertNull($result->customerEmail);
    }

    /** A real charge.refunded body: the object is the Charge, not the session. */
    private function chargeRefunded(int $amount, int $refunded): array
    {
        return [
            'id' => 'evt_1P9xRefund',
            'object' => 'event',
            'type' => 'charge.refunded',
            'created' => 1770000900,
            'data' => [
                'object' => [
                    'id' => 'ch_3P9xTest',
                    'object' => 'charge',
                    'amount' => $amount,
                    'amount_refunded' => $refunded,
                    'currency' => 'eur',
                    'captured' => true,
                    'paid' => true,
                    'payment_intent' => 'pi_3P9xTest',
                    'refunded' => $refunded >= $amount,
                    'status' => 'succeeded',
                ],
            ],
        ];
    }

    public function testAFullRefundIsRecognisedAndCarriesThePaymentItReverses(): void
    {
        $result = $this->parse($this->chargeRefunded(3625, 3625));

        self::assertTrue($result->isRefund);
        self::assertFalse($result->isPaid);
        self::assertSame('pi_3P9xTest', $result->paymentIntentId, 'the refund is matched to the order by its payment intent');
    }

    /**
     * ⚠ Partial refunds are deliberately ignored — refunding part of an order does not make the
     * order refunded, and treating it as one would reverse a sale that is still standing.
     */
    public function testAPartialRefundIsNotTreatedAsARefund(): void
    {
        self::assertFalse($this->parse($this->chargeRefunded(3625, 1000))->isRefund);
    }

    /** ⚠ An unsigned or wrongly-signed body must never reach the parser. */
    public function testAWronglySignedBodyIsRefused(): void
    {
        $payload = json_encode($this->checkoutCompleted(), \JSON_THROW_ON_ERROR);

        $this->expectException(\Stripe\Exception\SignatureVerificationException::class);
        $this->stripe()->parseSignedWebhook($payload, [
            'stripe-signature' => \sprintf('t=%d,v1=%s', time(), str_repeat('0', 64)),
        ]);
    }
}
