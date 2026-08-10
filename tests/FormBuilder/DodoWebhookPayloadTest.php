<?php

namespace App\Tests\FormBuilder;

use App\Settings\SettingsManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Tallyst\FormBuilder\Payment\DodoProcessor;

/**
 * What we read OUT of a Dodo webhook body — the buyer's details above all.
 *
 * ⚠ WHY THIS EXISTS. On 2026-08-10 a Customer→Member rename replaced `$data['customer']['email']`
 * with `$data['member']['email']` in the parser. `customer` there is DODO'S key in DODO'S payload,
 * not our word for anything, so the rename silently broke the buyer's e-mail, name and phone on
 * every Dodo sale — no exception, just nulls, and the confirmation mail with nowhere to go. The
 * whole 585-test suite stayed green, because nothing read a real payload. It was caught by reading
 * a diff, which is not a control.
 *
 * ⚠ The payload shape below is the LIVE-PROVEN one (Faza 6–8): `type`, `data.payment_id`,
 * `data.customer.{email,name,phone_number}`, `data.metadata.order_id`, `data.invoice_url`, and the
 * finance fields. Keep it matching what Dodo actually sends — a fixture invented to match our own
 * parser would prove nothing.
 */
class DodoWebhookPayloadTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    private function dodo(): DodoProcessor
    {
        return new DodoProcessor(
            new MockHttpClient(),
            $this->createStub(SettingsManager::class),
            new NullLogger(),
            'dodo_test_key',
            self::SECRET,
            'test',
        );
    }

    /** Signs a body the way Dodo does (Standard Webhooks), so parsing is reached at all. */
    private function parse(array $event): \Tallyst\FormBuilder\Payment\WebhookResult
    {
        $payload = json_encode($event, \JSON_THROW_ON_ERROR);
        $id = 'msg_test';
        $timestamp = (string) time();

        return $this->dodo()->parseSignedWebhook($payload, [
            'webhook-id' => $id,
            'webhook-timestamp' => $timestamp,
            'webhook-signature' => 'v1,'.base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$payload, self::SECRET, true)),
        ]);
    }

    /** A real payment.succeeded body, as Dodo sends it. */
    private function paymentSucceeded(): array
    {
        return [
            'type' => 'payment.succeeded',
            'data' => [
                'payment_id' => 'pay_9f2c',
                'customer' => [
                    'email' => 'buyer@example.com',
                    'name' => 'Pero Perić',
                    'phone_number' => '+385911234567',
                ],
                'metadata' => ['order_id' => '4242'],
                'invoice_url' => 'https://checkout.dodopayments.com/invoice/inv_1',
                'tax' => 725,
                'total_amount' => 3625,
                'settlement_amount' => 3400,
                'settlement_currency' => 'EUR',
            ],
        ];
    }

    /**
     * ⚠ THE ONE THAT WOULD HAVE CAUGHT THE RENAME. Everything the buyer is known by comes out of
     * Dodo's `customer` object; if that key stops being read, the sale still records but the person
     * behind it does not, and the confirmation mail has no recipient.
     */
    public function testTheBuyerIsReadFromDodosCustomerObject(): void
    {
        $result = $this->parse($this->paymentSucceeded());

        self::assertSame('buyer@example.com', $result->customerEmail, "Dodo's data.customer.email");
        self::assertSame('Pero Perić', $result->customerName, "Dodo's data.customer.name");
        self::assertSame('+385911234567', $result->customerPhone, "Dodo's data.customer.phone_number");
    }

    public function testThePaymentIsCorrelatedAndRecorded(): void
    {
        $result = $this->parse($this->paymentSucceeded());

        self::assertTrue($result->isPaid);
        self::assertSame('pay_9f2c', $result->paymentIntentId);
        self::assertSame('4242', $result->orderId, 'metadata.order_id is what ties the event to our order');
        self::assertSame('https://checkout.dodopayments.com/invoice/inv_1', $result->invoiceUrl);
        self::assertSame(725, $result->dodoTaxMinor);
        self::assertSame(3625, $result->dodoTotalMinor);
        self::assertSame(3400, $result->dodoSettlementMinor);
        self::assertSame('EUR', $result->dodoSettlementCurrency);
    }

    /** A payment with no customer block must degrade to nulls, not blow up mid-webhook. */
    public function testAMissingCustomerObjectIsSurvivable(): void
    {
        $event = $this->paymentSucceeded();
        unset($event['data']['customer']);

        $result = $this->parse($event);

        self::assertTrue($result->isPaid, 'the payment is still a payment');
        self::assertNull($result->customerEmail);
        self::assertNull($result->customerName);
    }

    /** The licence arrives in its own event, and carries no customer block at all. */
    public function testTheLicenceEventIsReadFromItsOwnKey(): void
    {
        $result = $this->parse([
            'type' => 'entitlement_grant.created',
            'data' => ['payment_id' => 'pay_9f2c', 'license_key' => ['key' => 'ARCA-1234-5678']],
        ]);

        self::assertTrue($result->isEntitlement);
        self::assertSame('ARCA-1234-5678', $result->licenseKey);
        self::assertSame('pay_9f2c', $result->paymentIntentId, 'entitlements correlate by payment_id');
        self::assertFalse($result->isPaid);
    }

    public function testARefundIsRecognised(): void
    {
        $event = $this->paymentSucceeded();
        $event['type'] = 'refund.succeeded';

        $result = $this->parse($event);

        self::assertTrue($result->isRefund);
        self::assertFalse($result->isPaid);
    }

    /** ⚠ An unsigned or wrongly-signed body must never reach the parser. */
    public function testAWronglySignedBodyIsRefused(): void
    {
        $payload = json_encode($this->paymentSucceeded(), \JSON_THROW_ON_ERROR);

        $this->expectException(\RuntimeException::class);
        $this->dodo()->parseSignedWebhook($payload, [
            'webhook-id' => 'msg_test',
            'webhook-timestamp' => (string) time(),
            'webhook-signature' => 'v1,'.base64_encode('not-the-signature'),
        ]);
    }
}
