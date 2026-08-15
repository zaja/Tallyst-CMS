<?php

namespace App\Tests\Functional;

use App\Entity\Member;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tallyst\FormBuilder\Entity\Order;

/**
 * A purchase made while already signed in must appear without signing in again.
 *
 * ⚠ THE GAP THIS CLOSES, MEASURED. Sales are attached to an account when somebody proves their
 * address, and a sign-in lasts 90 DAYS. A member who was already signed in when they bought
 * something therefore would not see it until their next sign-in — up to three months later. On
 * 2026-08-15 exactly that happened on the dev site: an abandoned checkout carrying the member's own
 * address was invisible on their account while the shop owner could see it in the admin.
 *
 * ⚠ AND WHAT IT MUST NOT DO. Attaching by an address a visitor merely TYPED is refused: anyone could
 * put someone else's address into a form, abandon the checkout, and have their own name and details
 * appear inside that person's account. Only an address the member has already proven counts, which
 * is why this hangs off opening the account rather than off the checkout.
 */
class MemberAccountClaimsOnViewTest extends WebTestCase
{
    /** @var int[] */
    private array $orderIds = [];

    protected function tearDown(): void
    {
        $conn = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        foreach ($this->orderIds as $id) {
            $conn->executeStatement('DELETE FROM fb_order WHERE id = ?', [$id]);
        }
        $conn->executeStatement("DELETE FROM `member` WHERE email LIKE 'claim-test-%'");
        $this->orderIds = [];
        parent::tearDown();
    }

    private function member(KernelBrowser $client): Member
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $member = new Member('claim-test-'.uniqid().'@example.com');
        $em->persist($member);
        $em->flush();

        return $member;
    }

    private function unboundOrder(KernelBrowser $client, string $email, string $status = Order::STATUS_FAILED): Order
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $order = (new Order())
            ->setProductName('Arca Backup')
            ->setAmountMinor(3625)
            ->setCurrency('eur')
            ->setStatus($status)
            ->setCustomerEmail($email)
            ->setThankYouToken(bin2hex(random_bytes(16)))
            ->setReturnPath('/buy-arca');
        if (Order::STATUS_FAILED === $status) {
            $order->setAbandonedAt(new \DateTimeImmutable());
        }
        $em->persist($order);
        $em->flush();
        $this->orderIds[] = $order->getId();

        return $order;
    }

    /** ⚠ THE ONE THAT MATTERS: opening the account is enough — no second sign-in. */
    public function testAPurchaseMadeWhileAlreadySignedInAppearsOnTheAccount(): void
    {
        $client = static::createClient();
        $member = $this->member($client);
        $order = $this->unboundOrder($client, $member->getEmail());
        $client->loginUser($member, 'member');

        $client->request('GET', '/account');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Arca Backup', (string) $client->getResponse()->getContent());

        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNotNull(
            $em->getRepository(Order::class)->find($order->getId())->getMember(),
            'the sale is now attached, not merely displayed',
        );
    }

    /** An unfinished purchase is shown too, with its way back — that is why somebody opens this page. */
    public function testAnUnfinishedPurchaseIsShownWithItsWayBack(): void
    {
        $client = static::createClient();
        $member = $this->member($client);
        $order = $this->unboundOrder($client, $member->getEmail());
        $client->loginUser($member, 'member');

        // The real path: the member opens their account, which is where the sale is claimed, and
        // follows the list. Going straight to the detail of an unclaimed order is a 404 by design —
        // that page shows only what the account already owns.
        $client->request('GET', '/account');
        $client->request('GET', '/account/purchase/'.$order->getId());

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        // Matched without the apostrophe: Twig escapes it to &#039;, and asserting on the raw
        // character would fail for a page that is perfectly correct.
        self::assertStringContainsString('completed and nothing was charged', $html);
        self::assertStringContainsString('/retry?t=', $html, 'the way back must be offered');
    }

    /**
     * ⚠ SOMEBODY ELSE'S SALE IS NEVER CLAIMED. Matching is on the address this member proved, so an
     * order under a different address stays where it is however often they open the page.
     */
    public function testASaleUnderAnotherAddressIsNeverClaimed(): void
    {
        $client = static::createClient();
        $member = $this->member($client);
        $stranger = $this->unboundOrder($client, 'somebody-else@example.com');
        $client->loginUser($member, 'member');

        $client->request('GET', '/account');

        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull(
            $em->getRepository(Order::class)->find($stranger->getId())->getMember(),
            "another person's purchase must not land in this account",
        );
    }

    /** Opening the page repeatedly is harmless — there is simply nothing left to claim. */
    public function testOpeningTheAccountAgainChangesNothing(): void
    {
        $client = static::createClient();
        $member = $this->member($client);
        $order = $this->unboundOrder($client, $member->getEmail(), Order::STATUS_PAID);
        $client->loginUser($member, 'member');

        $client->request('GET', '/account');
        $client->request('GET', '/account');

        self::assertResponseIsSuccessful();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame(
            $member->getId(),
            $em->getRepository(Order::class)->find($order->getId())->getMember()?->getId(),
        );
    }
}
