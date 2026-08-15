<?php

namespace App\Tests\Functional;

use App\Entity\Member;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tallyst\FormBuilder\Entity\Order;

/**
 * ⚠ THIS TEST IS A DELIBERATE WALL AGAINST SCOPE, NOT A CHECK ON THE IMPLEMENTATION.
 *
 * It is not here to prove the page renders correctly — MemberPurchaseAccessTest does that. It is
 * here to make a set of DECISIONS expensive to reverse by accident. Everything an order carries was
 * weighed once against a single question: does this belong to the BUYER, or to the OWNER of the
 * shop? Payment identifiers, the address the purchase was made from, and what the provider actually
 * settled after its fees all belong to the owner. They are not secret from the buyer so much as
 * none of their business, and putting them on this page invites questions the shop gains nothing
 * from answering.
 *
 * Those decisions live in prose in MemberPurchaseController's docblock, where a hurried change will
 * not read them. This is the part that fails.
 *
 * ⚠ IT ASSERTS ON VALUES, NOT ON FIELD NAMES, AND THAT IS THE WHOLE DESIGN. A list of today's
 * forbidden fields would pass happily for a column added in six months that nobody thought to add
 * to the list — the failure mode is silence, which is how the field would get shipped. So the
 * fixture fills every owner-only column with a value that could not occur by chance, and the test
 * asks a blunter question: did any of them reach the page? A new column shows up the moment its
 * value does.
 *
 * If you are here because this test failed after you added something to the page: that is the test
 * working. Decide deliberately whether the buyer should see it, and say so in the controller
 * docblock, before touching the fixture.
 */
class MemberPurchasePrivacyTest extends WebTestCase
{
    /**
     * Values chosen to be unmistakable in an HTML dump. Each one is a fact the SHOP keeps, not the
     * buyer: what the payment was called at the provider, where the purchase came from, and how the
     * money divided once the provider took its cut.
     */
    private const array OWNER_ONLY = [
        'session id' => 'cs_test_OWNERONLYSESSION',
        'payment intent' => 'pi_test_OWNERONLYINTENT',
        'provider unit id' => 'pdt_OWNERONLYUNIT',
        'buyer address' => '198.51.100.77',
        'buyer country' => 'OWNERONLYCOUNTRY',
        'vat id' => 'OWNERONLYVATID',
        'thank-you token' => 'OWNERONLYTHANKYOUTOKEN',
    ];

    /** @var int[] */
    private array $orderIds = [];

    protected function tearDown(): void
    {
        $conn = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        foreach ($this->orderIds as $id) {
            $conn->executeStatement('DELETE FROM fb_order WHERE id = ?', [$id]);
        }
        $conn->executeStatement("DELETE FROM `member` WHERE email LIKE 'privacy-test-%'");
        $this->orderIds = [];
        parent::tearDown();
    }

    private function member(KernelBrowser $client): Member
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $member = new Member('privacy-test-'.uniqid().'@example.com');
        $em->persist($member);
        $em->flush();

        return $member;
    }

    /** An order with EVERY owner-only field populated — the opposite of a minimal fixture, on purpose. */
    private function fullyPopulatedOrder(KernelBrowser $client, Member $owner): Order
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $order = (new Order())
            ->setMember($owner)
            ->setProductName('Arca Backup')
            ->setAmountMinor(3625)
            ->setCurrency('eur')
            ->setStatus(Order::STATUS_PAID)
            ->setProvider('dodo')
            ->setPaymentMode('test')
            ->setSubmissionData(['full_name' => 'Pero Perić', 'company' => 'Sve je dobro'])
            ->setLicenseKey('ARCA-1234-5678')
            ->setInvoiceUrl('https://checkout.dodopayments.com/invoice/inv_1')
            ->setTaxAmountMinor(725)
            ->setTaxName('PDV')
            ->setTaxRate('25.00')
            ->setProviderSessionId(self::OWNER_ONLY['session id'])
            ->setProviderPaymentIntentId(self::OWNER_ONLY['payment intent'])
            ->setProviderUnitId(self::OWNER_ONLY['provider unit id'])
            ->setCustomerIp(self::OWNER_ONLY['buyer address'])
            ->setCustomerCountry(self::OWNER_ONLY['buyer country'])
            ->setCustomerVatId(self::OWNER_ONLY['vat id'])
            ->setThankYouToken(self::OWNER_ONLY['thank-you token']);

        // The provider's own settlement figures: what it took, what it passed on. The owner's ledger.
        $order->setDodoTaxMinor(725)->setDodoTotalMinor(3625)->setDodoSettlementMinor(3400)
            ->setDodoSettlementCurrency('EUR');

        $em->persist($order);
        $em->flush();
        $this->orderIds[] = $order->getId();

        return $order;
    }

    /** ⚠ The wall. Any owner-only VALUE reaching the page fails this, named. */
    public function testNothingThatBelongsToTheOwnerReachesTheBuyersPage(): void
    {
        $client = static::createClient();
        $member = $this->member($client);
        $order = $this->fullyPopulatedOrder($client, $member);
        $client->loginUser($member, 'member');

        $client->request('GET', '/account/purchase/'.$order->getId());
        $html = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();

        foreach (self::OWNER_ONLY as $what => $value) {
            self::assertStringNotContainsString(
                $value,
                $html,
                \sprintf('The %s is the shop owner\'s record, not the buyer\'s — it must not appear on this page.', $what),
            );
        }

        // The settlement split, asserted separately: 3400 is what the provider passed on after fees,
        // and a buyer reading how much of their 36.25 the shop actually kept is a conversation
        // nobody asked for.
        self::assertStringNotContainsString('34,00', $html, 'the provider settlement must not appear');
        self::assertStringNotContainsString('3400', $html, 'the provider settlement must not appear');
    }

    /**
     * ⚠ No route out of this page into a provider's interface. The buyer must not be handed to a
     * screen the site owner does not control and cannot support.
     */
    public function testThereIsNoLinkIntoTheProvidersPortal(): void
    {
        $client = static::createClient();
        $member = $this->member($client);
        $order = $this->fullyPopulatedOrder($client, $member);
        $client->loginUser($member, 'member');

        $crawler = $client->request('GET', '/account/purchase/'.$order->getId());

        foreach ($crawler->filter('a')->extract(['href']) as $href) {
            self::assertStringNotContainsString('dashboard.stripe.com', (string) $href);
            self::assertStringNotContainsString('paypal.com', (string) $href);
            self::assertStringNotContainsString('app.dodopayments.com', (string) $href);
        }
    }

    /**
     * ⚠ NO CANCEL OR REFUND BUTTON, and the reason is not technical. A refund is a CONVERSATION, not
     * an action: a button turns a frustrated buyer into a refund instead of into a solved problem.
     * And on a Merchant-of-Record sale the refund is legally the provider's to give, so a button
     * here would claim the site owner decides when they do not.
     */
    public function testThereIsNothingToPressThatWouldStartARefund(): void
    {
        $client = static::createClient();
        $member = $this->member($client);
        $order = $this->fullyPopulatedOrder($client, $member);
        $client->loginUser($member, 'member');

        $crawler = $client->request('GET', '/account/purchase/'.$order->getId());

        self::assertCount(0, $crawler->filter('form'), 'the page submits nothing at all');
        foreach (['refund', 'cancel', 'povrat', 'otkaz'] as $word) {
            self::assertStringNotContainsStringIgnoringCase(
                $word,
                (string) $crawler->filter('article')->html(),
                'no refund or cancellation affordance',
            );
        }
    }

    /** What the buyer IS owed: the two things they opened the page for. */
    public function testTheBuyerDoesGetTheirLicenceAndInvoice(): void
    {
        $client = static::createClient();
        $member = $this->member($client);
        $order = $this->fullyPopulatedOrder($client, $member);
        $client->loginUser($member, 'member');

        $client->request('GET', '/account/purchase/'.$order->getId());
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('ARCA-1234-5678', $html);
        self::assertStringContainsString('checkout.dodopayments.com/invoice/inv_1', $html);
        self::assertStringContainsString('Pero Peri', $html, 'their own submitted details');
    }

    /** ⚠ An unfinished purchase shows one sentence, not empty boxes where a licence would be. */
    public function testAnUnfinishedPurchaseShowsNoEmptyLicenceOrInvoice(): void
    {
        $client = static::createClient();
        $member = $this->member($client);
        $order = $this->fullyPopulatedOrder($client, $member);
        $order->setStatus(Order::STATUS_PENDING);
        $client->getContainer()->get(EntityManagerInterface::class)->flush();
        $client->loginUser($member, 'member');

        $client->request('GET', '/account/purchase/'.$order->getId());
        $html = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString('ARCA-1234-5678', $html, 'no licence before payment lands');
        self::assertStringNotContainsString('member-licence', $html, 'not an empty licence box either');
        self::assertStringContainsString('never completed', $html);
    }
}
