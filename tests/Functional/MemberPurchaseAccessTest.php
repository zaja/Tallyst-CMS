<?php

namespace App\Tests\Functional;

use App\Entity\Member;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tallyst\FormBuilder\Entity\Order;

/**
 * Who may open a purchase, and what a stranger learns by trying.
 *
 * ⚠ THE POINT IS THE SAMENESS, not the refusal. Somebody else's order and an order that was never
 * created must produce the SAME answer. "This is not yours" would confirm that the id is real and
 * belongs to somebody — which is precisely what a stranger walking the numbers is trying to find
 * out, and the same reasoning that makes the sign-in form answer identically for known and unknown
 * addresses.
 */
class MemberPurchaseAccessTest extends WebTestCase
{
    /** @var int[] */
    private array $orderIds = [];

    /**
     * ⚠ Rows created here must not survive the run — several tests in this suite count whole tables
     * (OrderDashboardStatsTest is the worked example) and one stray order breaks them with numbers
     * that have nothing to do with what they assert.
     */
    protected function tearDown(): void
    {
        $conn = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        foreach ($this->orderIds as $id) {
            $conn->executeStatement('DELETE FROM fb_order WHERE id = ?', [$id]);
        }
        $conn->executeStatement("DELETE FROM `member` WHERE email LIKE 'purchase-test-%'");
        $this->orderIds = [];
        parent::tearDown();
    }

    private function member(KernelBrowser $client): Member
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $member = new Member('purchase-test-'.uniqid().'@example.com');
        $em->persist($member);
        $em->flush();

        return $member;
    }

    private function order(KernelBrowser $client, ?Member $owner): Order
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $order = (new Order())
            ->setProductName('Arca Backup')
            ->setAmountMinor(3625)
            ->setCurrency('eur')
            ->setStatus(Order::STATUS_PAID);
        if (null !== $owner) {
            $order->setMember($owner);
        }
        $em->persist($order);
        $em->flush();
        $this->orderIds[] = $order->getId();

        return $order;
    }

    public function testAMemberSeesTheirOwnPurchase(): void
    {
        $client = static::createClient();
        $member = $this->member($client);
        $order = $this->order($client, $member);
        $client->loginUser($member, 'member');

        $client->request('GET', '/account/purchase/'.$order->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Arca Backup', (string) $client->getResponse()->getContent());
    }

    /**
     * ⚠ THE ONE THAT MATTERS. Someone else's order and a nonexistent one must be indistinguishable.
     * Any difference turns the id in the URL into a way of asking "did this person buy something?".
     *
     * ⚠ WHY THIS IS NOT A BYTE COMPARISON, unlike the equivalent test on the sign-in form. Sameness
     * here is guaranteed by CONSTRUCTION, not by coincidence: the controller has ONE branch and one
     * `createNotFoundException()` with no message, so both cases leave through the same line and a
     * production install renders one identical page. What a byte comparison would actually measure
     * in this environment is the DEVELOPER exception page, which carries a fresh object hash and the
     * calling line number and therefore differs between any two requests — a red test that proves
     * nothing. (Booting a non-debug kernel to dodge that builds a second container nothing else
     * warms; it was tried, and it 500s on stale code.)
     *
     * So this asserts the two things that carry information to a stranger: the status, and that
     * neither answer says anything about the order behind the id.
     */
    public function testSomebodyElsesPurchaseAnswersExactlyLikeOneThatDoesNotExist(): void
    {
        $client = static::createClient();
        $stranger = $this->member($client);
        $owner = $this->member($client);
        $theirs = $this->order($client, $owner);
        $client->loginUser($stranger, 'member');

        $client->request('GET', '/account/purchase/'.$theirs->getId());
        $foreignStatus = $client->getResponse()->getStatusCode();
        $foreignBody = (string) $client->getResponse()->getContent();

        $client->request('GET', '/account/purchase/99999999');
        $missingStatus = $client->getResponse()->getStatusCode();
        $missingBody = (string) $client->getResponse()->getContent();

        self::assertSame(404, $foreignStatus);
        self::assertSame($missingStatus, $foreignStatus, 'same status');

        // The same refusal, worded the same way — not "this is not yours" for one and "no such
        // thing" for the other.
        self::assertStringContainsString('Not Found', $foreignBody);
        self::assertStringContainsString('Not Found', $missingBody);

        // And nothing about the order leaks into the refusal.
        self::assertStringNotContainsString('Arca Backup', $foreignBody, 'the refusal must not name the product');
        self::assertStringNotContainsString('Arca Backup', $missingBody);
    }

    /** An order nobody has claimed is nobody's to read, even by a signed-in member. */
    public function testAnUnclaimedPurchaseIsNotReadable(): void
    {
        $client = static::createClient();
        $member = $this->member($client);
        $orphan = $this->order($client, null);
        $client->loginUser($member, 'member');

        $client->request('GET', '/account/purchase/'.$orphan->getId());

        self::assertResponseStatusCodeSame(404);
    }

    /** ⚠ Signed out, this is not a page at all — the member firewall sends them to sign in. */
    public function testASignedOutVisitorCannotOpenIt(): void
    {
        $client = static::createClient();
        $order = $this->order($client, $this->member($client));

        $client->request('GET', '/account/purchase/'.$order->getId());

        self::assertResponseRedirects();
        self::assertStringContainsString('/account/login', (string) $client->getResponse()->headers->get('Location'));
    }
}
