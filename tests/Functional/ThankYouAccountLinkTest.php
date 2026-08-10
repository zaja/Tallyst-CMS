<?php

namespace App\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tallyst\FormBuilder\Entity\Order;

/**
 * ⚠ THE THANK-YOU PAGE MUST NOT DEPEND ON E-MAIL.
 *
 * The buyer has just paid and is standing on the page, unsure whether it worked — the worst possible
 * moment to make them wait for a message. Mail here goes out asynchronously through a worker, so if
 * the page only announced "we have sent you an overview", a stopped or backed-up worker would leave
 * them with nothing at all.
 *
 * The order already carries its own unguessable token, which proves "you are the person who just
 * completed this checkout". That is enough to show this one purchase immediately. It is NOT proof
 * that they hold the address, so no account is created here — the account link is offered, not taken.
 */
class ThankYouAccountLinkTest extends WebTestCase
{
    use MailerAssertionsTrait;

    /** @var list<int> */
    private array $orderIds = [];

    /**
     * ⚠ Rows created here MUST be removed again. OrderDashboardStatsTest counts every order in the
     * table and cleans up only its own, so anything left behind here silently breaks it — which is
     * exactly what happened before this teardown existed.
     */
    protected function tearDown(): void
    {
        if ([] !== $this->orderIds) {
            $conn = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
            foreach ($this->orderIds as $id) {
                $conn->executeStatement('DELETE FROM fb_order WHERE id = ?', [$id]);
            }
            $this->orderIds = [];
        }
        parent::tearDown();
    }

    private function order(KernelBrowser $client, ?string $email): Order
    {
        /** @var EntityManagerInterface $em */
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $order = (new Order())
            ->setStatus(Order::STATUS_PAID)
            ->setAmountMinor(2900)
            ->setCurrency('eur')
            ->setProductName('Arca Pro')
            ->setProvider('stripe')
            ->setThankYouToken(bin2hex(random_bytes(16)));
        if (null !== $email) {
            $order->setCustomerEmail($email);
        }

        $em->persist($order);
        $em->flush();
        $this->orderIds[] = (int) $order->getId();

        return $order;
    }

    private function url(Order $order): string
    {
        return '/form/order/'.$order->getId().'/thank-you?t='.$order->getThankYouToken();
    }

    /** The purchase is visible with no mail involved at all. */
    public function testThePurchaseIsShownWithoutAnyMailBeingSent(): void
    {
        $client = static::createClient();
        $order = $this->order($client, 'buyer.'.uniqid().'@example.com');

        $client->request('GET', $this->url($order));

        self::assertResponseIsSuccessful();
        self::assertQueuedEmailCount(0, message: 'the thank-you page must not need to send anything');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Arca Pro', $html, 'the buyer must see WHAT they bought');
        self::assertStringContainsString('29', $html, 'and how much it cost');
    }

    /** An address on the order means we can offer the account without asking them to type it. */
    public function testTheAccountLinkIsOfferedWhenTheOrderHasAnAddress(): void
    {
        $client = static::createClient();
        $order = $this->order($client, 'buyer.'.uniqid().'@example.com');

        $client->request('GET', $this->url($order));

        self::assertGreaterThan(
            0,
            $client->getCrawler()->filter('form[action*="account-link"]')->count(),
            'the page should offer to send a link to the address already on the order',
        );
    }

    /**
     * ⚠ No address yet — the webhook may not have landed. The offer must simply not appear, rather
     * than showing a broken button or asking the buyer to type an address they already gave.
     */
    public function testNoOfferWhenTheOrderHasNoAddressYet(): void
    {
        $client = static::createClient();
        $order = $this->order($client, null);

        $client->request('GET', $this->url($order));

        self::assertResponseIsSuccessful();
        self::assertSame(
            0,
            $client->getCrawler()->filter('form[action*="account-link"]')->count(),
            'nothing to send to, so nothing should be offered',
        );
    }

    public function testAskingForTheLinkSendsItToTheAddressOnTheOrder(): void
    {
        $client = static::createClient();
        $email = 'buyer.'.uniqid().'@example.com';
        $order = $this->order($client, $email);

        $client->request('GET', $this->url($order));
        $client->submit($client->getCrawler()->filter('form[action*="account-link"]')->form());

        self::assertResponseRedirects();
        self::assertQueuedEmailCount(1);
        self::assertEmailAddressContains(self::getMailerMessage(), 'To', $email);
    }

    /**
     * ⚠ The token guards the POST too. Without this, anyone could make the site send mail to the
     * address on any order just by guessing an id.
     */
    public function testAskingForTheLinkWithoutTheTokenIsRefused(): void
    {
        $client = static::createClient();
        $order = $this->order($client, 'buyer.'.uniqid().'@example.com');

        $client->request('POST', '/form/order/'.$order->getId().'/account-link');

        self::assertResponseStatusCodeSame(404);
        self::assertQueuedEmailCount(0);
    }
}
