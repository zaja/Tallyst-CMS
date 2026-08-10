<?php

namespace App\Tests\FormBuilder;

use App\Settings\SettingsManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tallyst\FormBuilder\Entity\Order;
use Tallyst\FormBuilder\Payment\PayPalProcessor;

/**
 * What we read OUT of a PayPal capture response — the buyer's e-mail above all.
 *
 * ⚠ PAYPAL IS THE ODD ONE OUT, AND THAT IS THE POINT OF THIS TEST. Stripe and Dodo hand us the
 * buyer in the WEBHOOK. PayPal does not send it there reliably, so the e-mail is taken from the
 * response to the CAPTURE call instead — on the buyer's return from PayPal, in `finalizeReturn()`.
 * `parseSignedWebhook()` deliberately reports `customerEmail: null`; that is not an oversight and
 * must not be "fixed" to match the other two. Anyone tidying the three providers into one shape
 * would move the read to the webhook, where the field is not there, and every PayPal sale would
 * quietly lose its buyer.
 *
 * ⚠ SOURCE OF THE SHAPE. This fixture comes from PayPal's DOCUMENTED Orders v2 capture response,
 * NOT from a response recorded on this system — unlike the Dodo fixture, taken from live traffic.
 * The load-bearing fields, to be confirmed against the first live PayPal purchase here:
 *
 *   payment_source.paypal.email_address              — the buyer, first choice
 *   payer.email_address                              — the buyer, fallback
 *   purchase_units[0].payments.captures[0].id        — the capture, what a refund later acts on
 *
 * Everything else in the fixture is context: present because PayPal sends it, unread by us.
 */
class PayPalCapturePayloadTest extends TestCase
{
    /** A real Orders v2 capture response, as PayPal returns it. */
    private function captureResponse(): array
    {
        return [
            'id' => 'ORD-1',
            'status' => 'COMPLETED',
            'payment_source' => [
                'paypal' => [
                    'email_address' => 'buyer@example.com',
                    'account_id' => 'QYR5Z8XDVJNXQ',
                    'account_status' => 'VERIFIED',
                    'name' => ['given_name' => 'Pero', 'surname' => 'Perić'],
                    'address' => ['country_code' => 'HR'],
                ],
            ],
            'purchase_units' => [[
                'reference_id' => 'default',
                'shipping' => [
                    'name' => ['full_name' => 'Pero Perić'],
                    'address' => [
                        'address_line_1' => 'Ilica 1',
                        'admin_area_2' => 'Zagreb',
                        'postal_code' => '10000',
                        'country_code' => 'HR',
                    ],
                ],
                'payments' => [
                    'captures' => [[
                        'id' => 'CAP-1',
                        'status' => 'COMPLETED',
                        'amount' => ['currency_code' => 'EUR', 'value' => '36.25'],
                        'final_capture' => true,
                        'seller_protection' => ['status' => 'ELIGIBLE'],
                        'create_time' => '2026-08-10T12:00:00Z',
                        'update_time' => '2026-08-10T12:00:00Z',
                        'links' => [
                            ['href' => 'https://api.sandbox.paypal.com/v2/payments/captures/CAP-1', 'rel' => 'self', 'method' => 'GET'],
                            ['href' => 'https://api.sandbox.paypal.com/v2/payments/captures/CAP-1/refund', 'rel' => 'refund', 'method' => 'POST'],
                        ],
                    ]],
                ],
            ]],
            'payer' => [
                'name' => ['given_name' => 'Pero', 'surname' => 'Perić'],
                'email_address' => 'payer@example.com',
                'payer_id' => 'QYR5Z8XDVJNXQ',
                'address' => ['country_code' => 'HR'],
            ],
            'links' => [
                ['href' => 'https://api.sandbox.paypal.com/v2/checkout/orders/ORD-1', 'rel' => 'self', 'method' => 'GET'],
            ],
        ];
    }

    /**
     * Answers the two calls `finalizeReturn()` makes, in order: the OAuth token, then the capture.
     * Nothing here touches the network.
     */
    private function processor(array $captureBody, int $captureStatus = 201): PayPalProcessor
    {
        $settings = $this->createStub(SettingsManager::class);
        $settings->method('get')->willReturn(null);

        $responses = [
            new MockResponse(json_encode(['access_token' => 'A21AA-test', 'expires_in' => 32400], \JSON_THROW_ON_ERROR), [
                'response_headers' => ['content-type' => 'application/json'],
            ]),
            new MockResponse(json_encode($captureBody, \JSON_THROW_ON_ERROR), [
                'http_code' => $captureStatus,
                'response_headers' => ['content-type' => 'application/json'],
            ]),
        ];

        return new PayPalProcessor(
            new MockHttpClient($responses),
            $settings,
            new ArrayAdapter(),
            new NullLogger(),
            'client-id',
            'client-secret',
            'WH-TEST-ID',
            'sandbox',
        );
    }

    private function pendingOrder(): Order
    {
        return (new Order())->setProviderSessionId('ORD-1');
    }

    /**
     * ⚠ THE ONE THAT MATTERS. This is the only place a PayPal buyer's e-mail ever enters the system.
     * If this read breaks, the sale still records and nothing throws — the customer simply never
     * hears from the shop, and the order carries no address to reach them at.
     */
    public function testTheBuyerIsReadFromThePaymentSource(): void
    {
        $order = $this->pendingOrder();

        $this->processor($this->captureResponse())->finalizeReturn($order);

        self::assertSame('buyer@example.com', $order->getCustomerEmail(), "PayPal's payment_source.paypal.email_address");
    }

    /** Older/alternate funding paths carry the address under `payer` instead. */
    public function testTheBuyerFallsBackToThePayer(): void
    {
        $body = $this->captureResponse();
        unset($body['payment_source']);

        $order = $this->pendingOrder();
        $this->processor($body)->finalizeReturn($order);

        self::assertSame('payer@example.com', $order->getCustomerEmail(), "PayPal's payer.email_address");
    }

    /**
     * The capture id is stored on return rather than waiting for the webhook, so a refund works
     * even if the webhook is late or never arrives.
     */
    public function testTheCaptureIsRecordedSoARefundHasSomethingToActOn(): void
    {
        $order = $this->pendingOrder();

        $this->processor($this->captureResponse())->finalizeReturn($order);

        self::assertSame('CAP-1', $order->getProviderPaymentIntentId());
    }

    /**
     * ⚠ Capturing must NOT mark the order paid. The verified webhook stays the sole source of truth
     * for money — a buyer who merely returns from PayPal has not been charged by us saying so.
     */
    public function testReturningFromPayPalDoesNotMakeTheOrderPaid(): void
    {
        $order = $this->pendingOrder();

        $this->processor($this->captureResponse())->finalizeReturn($order);

        self::assertFalse($order->isPaid());
    }

    /** An address already on the order (from the form) is not overwritten by PayPal's. */
    public function testAnAddressAlreadyOnTheOrderIsKept(): void
    {
        $order = $this->pendingOrder()->setCustomerEmail('from-the-form@example.com');

        $this->processor($this->captureResponse())->finalizeReturn($order);

        self::assertSame('from-the-form@example.com', $order->getCustomerEmail());
    }

    /** A capture response with no buyer must degrade to null, not blow up on the thank-you page. */
    public function testAMissingBuyerIsSurvivable(): void
    {
        $body = $this->captureResponse();
        unset($body['payment_source'], $body['payer']);

        $order = $this->pendingOrder();
        $this->processor($body)->finalizeReturn($order);

        self::assertNull($order->getCustomerEmail());
        self::assertSame('CAP-1', $order->getProviderPaymentIntentId(), 'the capture is still recorded');
    }

    /**
     * ⚠ A second return (refresh, or a race with the webhook) must be a no-op, not an error page:
     * PayPal answers ORDER_ALREADY_CAPTURED and the buyer has done nothing wrong.
     */
    public function testASecondReturnIsANoOpRatherThanAnError(): void
    {
        $order = $this->pendingOrder();

        $this->processor(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'ORDER_ALREADY_CAPTURED']]], 422)
            ->finalizeReturn($order);

        self::assertNull($order->getCustomerEmail());
    }
}
