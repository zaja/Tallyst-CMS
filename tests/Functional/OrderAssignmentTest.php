<?php

namespace App\Tests\Functional;

use App\Entity\Member;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tallyst\FormBuilder\Entity\Order;

/**
 * Assigning an order to an account by hand.
 *
 * ⚠ This screen is not a convenience. With the hard order-to-account link, it is the ONLY way back
 * for a buyer who loses access to their mailbox before changing their address: no password, no
 * second factor, so their account is otherwise locked for good. It also covers an address mistyped
 * at the payment provider, which is the case it was originally scoped for — the smaller of the two.
 */
class OrderAssignmentTest extends WebTestCase
{
    /** @var list<int> */
    private array $orderIds = [];

    protected function tearDown(): void
    {
        $conn = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        foreach ($this->orderIds as $id) {
            $conn->executeStatement('DELETE FROM fb_order WHERE id = ?', [$id]);
        }
        $conn->executeStatement("DELETE FROM `member` WHERE email LIKE 'assign.%@example.com'");
        $this->orderIds = [];
        parent::tearDown();
    }

    private function admin(KernelBrowser $client): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy([]);
        if (null === $admin) {
            self::markTestSkipped('no user in the test database');
        }
        $client->loginUser($admin);
    }

    private function order(?Member $member = null, ?string $email = null): Order
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $order = (new Order())
            ->setStatus(Order::STATUS_PAID)
            ->setAmountMinor(1500)
            ->setCurrency('eur')
            ->setProductName('Unclaimed thing')
            ->setCustomerEmail($email)
            ->setMember($member);
        $em->persist($order);
        $em->flush();
        $this->orderIds[] = (int) $order->getId();

        return $order;
    }

    private function customer(): Member
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $c = new Member('assign.'.uniqid().'@example.com');
        $em->persist($c);
        $em->flush();

        return $c;
    }

    public function testTheScreenListsOrdersThatBelongToNobody(): void
    {
        $client = static::createClient();
        $this->admin($client);
        $unclaimed = $this->order();

        $client->request('GET', '/admin/order-assignment');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('#'.$unclaimed->getId(), (string) $client->getResponse()->getContent());
    }

    public function testAnOrderThatAlreadyHasAnAccountIsNotListed(): void
    {
        $client = static::createClient();
        $this->admin($client);
        $claimed = $this->order($this->customer());

        $client->request('GET', '/admin/order-assignment');

        self::assertStringNotContainsString(
            'value="'.$claimed->getId().'"',
            (string) $client->getResponse()->getContent(),
            'an order that already belongs to somebody has nothing to assign',
        );
    }

    public function testAssigningAttachesTheOrderToTheAccount(): void
    {
        $client = static::createClient();
        $this->admin($client);
        $order = $this->order();
        $member = $this->customer();

        // Submit the real form from the real page — the way an admin does it, and the only way to
        // get a CSRF token that the same session will accept.
        $client->request('GET', '/admin/order-assignment?q='.urlencode($member->getEmail()));
        $form = $client->getCrawler()->filter('form[method="post"]')->form();
        $form['order'] = (string) $order->getId();
        $client->submit($form);

        self::assertResponseRedirects();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->getRepository(Order::class)->find($order->getId());
        self::assertSame($member->getId(), $fresh?->getMember()?->getId());
    }

    /** ⚠ Without CSRF, a link in an e-mail could move somebody's purchase to a stranger's account. */
    public function testAssigningWithoutAValidTokenChangesNothing(): void
    {
        $client = static::createClient();
        $this->admin($client);
        $order = $this->order();
        $member = $this->customer();

        $client->request('POST', '/admin/order-assignment/assign', [
            'order' => $order->getId(),
            'member' => $member->getId(),
            '_token' => 'not-a-token',
        ]);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->getRepository(Order::class)->find($order->getId())?->getMember());
    }

    /**
     * ⚠ The search form MUST carry an explicit action.
     *
     * EasyAdmin's sidebar links to `/admin?routeName=form_builder_order_assignment` and renders this
     * screen at that URL. A GET form with no action submits to the current path, and a browser
     * REPLACES the whole query string — so `routeName` is dropped and the admin lands on the
     * dashboard instead of their results. That is what happened in a real browser.
     *
     * This asserts the rule rather than the behaviour, deliberately: the test client keeps the
     * existing query string where a browser discards it, so no functional test here can reproduce
     * the failure itself. Structure is the part that IS checkable.
     */
    public function testTheSearchFormHasAnExplicitAction(): void
    {
        $client = static::createClient();
        $this->admin($client);

        $crawler = $client->request('GET', '/admin?routeName=form_builder_order_assignment');
        self::assertResponseIsSuccessful();

        $action = $crawler->filter('form[method="get"]')->attr('action');

        self::assertNotEmpty($action, 'without an action the search submits to /admin — the dashboard');
        self::assertStringContainsString('/admin/order-assignment', (string) $action);
    }

    /** Searching by address is how an admin finds the right account among many. */
    public function testAccountsCanBeSearchedByAddress(): void
    {
        $client = static::createClient();
        $this->admin($client);
        $member = $this->customer();

        $client->request('GET', '/admin/order-assignment?q='.urlencode($member->getEmail()));

        self::assertStringContainsString($member->getEmail(), (string) $client->getResponse()->getContent());
    }
}
