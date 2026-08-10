<?php

namespace App\Tests\Functional;

use App\Entity\Customer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The hard boundary from the design: a customer is NOT an admin.
 *
 * ⚠ This is the test that matters most in the whole feature. `^/admin` is fail-open — any new
 * back-office screen is reachable by an editor unless somebody remembers to lock it — so the thing
 * that must never happen is a buyer session counting as staff. These assertions are what stops a
 * future change to the firewalls from quietly opening that door.
 */
class CustomerAccountAccessTest extends WebTestCase
{
    /**
     * ⚠ Rows created here must not survive the run. Nothing counts customers today, but leaving
     * them behind is exactly how this suite's order tests broke earlier in this feature: a test
     * that counts a whole table is one stray row away from failing for reasons nobody can see.
     */
    protected function tearDown(): void
    {
        $conn = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $conn->executeStatement("DELETE FROM customer_login_request WHERE email LIKE 'buyer%@example.com'");
        $conn->executeStatement("DELETE FROM customer WHERE email LIKE 'buyer%@example.com'");
        parent::tearDown();
    }

    private function customer(KernelBrowser $client, string $email = 'buyer@example.com'): Customer
    {
        /** @var EntityManagerInterface $em */
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $customer = new Customer($email.'.'.uniqid().'@example.com');
        $em->persist($customer);
        $em->flush();

        return $customer;
    }

    public function testAnonymousVisitorIsSentToTheLoginPageNotAnError(): void
    {
        $client = static::createClient();
        $client->request('GET', '/account');

        self::assertResponseRedirects();
        self::assertStringContainsString('/account/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testTheLoginPageIsPublic(): void
    {
        $client = static::createClient();
        $client->request('GET', '/account/login');

        self::assertResponseIsSuccessful();
    }

    public function testALoggedInCustomerSeesTheirAccount(): void
    {
        $client = static::createClient();
        $client->loginUser($this->customer($client), 'customer');

        $client->request('GET', '/account');

        self::assertResponseIsSuccessful();
    }

    /** ⚠ The boundary: a customer session must be worth nothing in the back-office. */
    public function testACustomerCannotReachTheAdmin(): void
    {
        $client = static::createClient();
        $client->loginUser($this->customer($client), 'customer');

        $client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(302, 'a customer must be bounced to the admin login, never let in');
        self::assertStringContainsString('/admin/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testACustomerCannotReachAnAdminOnlyScreenEither(): void
    {
        $client = static::createClient();
        $client->loginUser($this->customer($client), 'customer');

        $client->request('GET', '/admin/settings');

        self::assertTrue(
            $client->getResponse()->isRedirect() || 403 === $client->getResponse()->getStatusCode(),
            'a customer must never get a 200 on an admin screen',
        );
    }

    /**
     * And the reverse: staff are not customers. An admin session must not silently act as a buyer's
     * account, or an owner browsing their own site could end up looking at somebody's purchases.
     */
    public function testAnAdminSessionIsNotACustomerSession(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(\App\Entity\User::class)->findOneBy([]);

        if (null === $admin) {
            self::markTestSkipped('no user in the test database to log in as');
        }

        $client->loginUser($admin);
        $client->request('GET', '/account');

        self::assertResponseRedirects();
        self::assertStringContainsString('/account/login', (string) $client->getResponse()->headers->get('Location'));
    }
}
