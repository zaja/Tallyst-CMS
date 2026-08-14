<?php

namespace App\Tests\FormBuilder;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tallyst\FormBuilder\Entity\Order;
use Tallyst\FormBuilder\Payment\WebhookResult;
use Tallyst\FormBuilder\Service\OrderPaymentSync;

/**
 * OrderPaymentSync::apply() called with the FULL shape each provider really sends on a sale.
 *
 * ⚠ WHY THIS EXISTS. This exact call — a payment WITH the buyer's e-mail — is what every Stripe and
 * every Dodo webhook makes in production, and until 2026-08-14 no test made it. A Customer→Member
 * rename had left `$this->customers->findByEmail()` behind on a constructor property that was now
 * called `$members`, so the call fatals: the webhook answers 500, the provider retries and gets 500
 * again, and the order NEVER becomes paid. The buyer is charged, the shop records nothing, no
 * confirmation, no licence, and the order sits in "awaiting payment" for ever. It shipped in v1.12.0.
 *
 * ⚠ THIS WAS THE THIRD MISS FROM THE SAME RENAME, and all three passed through a fully green suite,
 * because the suite never exercised the real call. Tests that assert on branches nobody takes in
 * production measure nothing. Every provider therefore gets its own case here, built from what that
 * provider actually sends.
 */
class OrderPaymentSyncProviderShapeTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    /** @var int[] */
    private array $orderIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    /** ⚠ Other tests count whole tables — nothing created here may survive the run. */
    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        foreach ($this->orderIds as $id) {
            $conn->executeStatement('DELETE FROM fb_order WHERE id = ?', [$id]);
        }
        $this->orderIds = [];
        parent::tearDown();
    }

    private function pendingOrder(string $sessionId): Order
    {
        $order = (new Order())
            ->setProductName('Arca Backup')
            ->setAmountMinor(3625)
            ->setCurrency('eur')
            ->setStatus(Order::STATUS_PENDING)
            ->setProviderSessionId($sessionId);
        $this->em->persist($order);
        $this->em->flush();
        $this->orderIds[] = $order->getId();

        return $order;
    }

    private function sync(): OrderPaymentSync
    {
        return static::getContainer()->get(OrderPaymentSync::class);
    }

    /**
     * ⚠ STRIPE SENDS THE BUYER'S E-MAIL. `customer_details.email` is on essentially every completed
     * Checkout Session, so this is the ordinary path, not an edge case.
     */
    public function testAStripeSaleIsRecorded(): void
    {
        $order = $this->pendingOrder('cs_test_shape_stripe');

        $status = $this->sync()->apply(new WebhookResult(
            eventType: 'checkout.session.completed',
            sessionId: 'cs_test_shape_stripe',
            paymentIntentId: 'pi_3P9xTest',
            isPaid: true,
            customerEmail: 'buyer@example.com',
        ));

        self::assertSame('OK', $status);
        $this->em->refresh($order);
        self::assertTrue($order->isPaid(), 'a paid Stripe checkout must be recorded as paid');
        self::assertSame('buyer@example.com', $order->getCustomerEmail());
    }

    /**
     * ⚠ DODO SENDS IT TOO, from `data.customer.email`, and additionally carries the licence, the
     * invoice and its settlement figures — so its result object is the fullest of the three.
     */
    public function testADodoSaleIsRecordedWithEverythingItCarries(): void
    {
        $order = $this->pendingOrder('dodo_shape_session');

        $status = $this->sync()->apply(new WebhookResult(
            eventType: 'payment.succeeded',
            sessionId: null,
            paymentIntentId: 'pay_9f2c',
            isPaid: true,
            customerEmail: 'buyer@example.com',
            orderId: (string) $order->getId(),
            customerName: 'Pero Perić',
            customerPhone: '+385911234567',
            invoiceUrl: 'https://checkout.dodopayments.com/invoice/inv_1',
            dodoTaxMinor: 725,
            dodoTotalMinor: 3625,
            dodoSettlementMinor: 3400,
            dodoSettlementCurrency: 'EUR',
        ));

        self::assertSame('OK', $status);
        $this->em->refresh($order);
        self::assertTrue($order->isPaid(), 'a paid Dodo purchase must be recorded as paid');
        self::assertSame('buyer@example.com', $order->getCustomerEmail());
        self::assertSame('Pero Perić', $order->getCustomerName());
        self::assertSame('https://checkout.dodopayments.com/invoice/inv_1', $order->getInvoiceUrl());
    }

    /**
     * ⚠ PAYPAL IS THE ODD ONE OUT AND THIS CASE MUST NOT BE "TIDIED UP" INTO THE OTHER TWO.
     *
     * Its webhook carries NO buyer e-mail — `PayPalProcessor::parseSignedWebhook` returns
     * `customerEmail: null` deliberately, because PayPal does not send it there reliably; the address
     * is read later from the capture response in `finalizeReturn()` instead. That null is a decision,
     * documented in CLAUDE.md.
     *
     * ⚠ It is also why PayPal was the ONE provider still working while Stripe and Dodo were broken:
     * with no e-mail, the crashing line was never reached. Anyone who "harmonises" these three tests
     * by giving PayPal an e-mail deletes the only case that covers its real shape — and removes the
     * evidence of which providers a future version of this bug would spare.
     */
    public function testAPayPalSaleIsRecordedWithoutAnyBuyerEmail(): void
    {
        $order = $this->pendingOrder('ORD-PAYPAL-1');

        $status = $this->sync()->apply(new WebhookResult(
            eventType: 'PAYMENT.CAPTURE.COMPLETED',
            sessionId: 'ORD-PAYPAL-1',
            paymentIntentId: 'CAP-1',
            isPaid: true,
            customerEmail: null,
        ));

        self::assertSame('OK', $status);
        $this->em->refresh($order);
        self::assertTrue($order->isPaid());
        self::assertNull($order->getCustomerEmail(), "PayPal's webhook carries no address — it arrives with the capture");
    }

    /** The buyer is bound to their account when one already exists for that address. */
    public function testASaleIsBoundToAnExistingAccountForThatAddress(): void
    {
        $email = 'shape-member-'.uniqid().'@example.com';
        $member = new \App\Entity\Member($email);
        $this->em->persist($member);
        $this->em->flush();

        $order = $this->pendingOrder('cs_test_shape_bind');
        $this->sync()->apply(new WebhookResult(
            eventType: 'checkout.session.completed',
            sessionId: 'cs_test_shape_bind',
            paymentIntentId: 'pi_bind',
            isPaid: true,
            customerEmail: $email,
        ));

        $this->em->refresh($order);
        self::assertNotNull($order->getMember(), 'an existing account must pick the sale up straight away');
        self::assertSame($email, $order->getMember()->getEmail());

        $this->em->remove($member);
        $this->em->flush();
    }

    /** No account for that address is the normal case — the order simply waits to be claimed. */
    public function testASaleWithNoAccountYetIsStillRecorded(): void
    {
        $order = $this->pendingOrder('cs_test_shape_noaccount');

        $this->sync()->apply(new WebhookResult(
            eventType: 'checkout.session.completed',
            sessionId: 'cs_test_shape_noaccount',
            paymentIntentId: 'pi_noacct',
            isPaid: true,
            customerEmail: 'nobody-'.uniqid().'@example.com',
        ));

        $this->em->refresh($order);
        self::assertTrue($order->isPaid());
        self::assertNull($order->getMember(), 'an account is never created here, only claimed');
    }
}
