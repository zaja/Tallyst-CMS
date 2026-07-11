<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tallyst\FormBuilder\Controller\Admin\OrderCrudController;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\FormSubmission;
use Tallyst\FormBuilder\Entity\Order;

/**
 * The order DETAIL page shows sections conditionally: the Merchant-of-Record (Dodo) panel + its fields
 * ONLY for a MoR order, and the delivery block (with a FORMATTED address, not raw ship_* keys) ONLY for
 * a shipped order. So a Stripe/PayPal order never shows empty Dodo fields.
 */
class OrderDetailRenderTest extends WebTestCase
{
    /** @var int[] */
    private array $orderIds = [];
    private ?int $formId = null;
    private ?int $submissionId = null;
    private array $emails = [];

    public function testStripeShippedOrderShowsFormattedAddressAndNoDodoSection(): void
    {
        $client = static::createClient();
        $order = $this->seedOrder('stripe', shipped: true);
        $client->loginUser($this->makeAdmin());

        $html = $this->detail($client, $order);

        // Delivery block with a FORMATTED mailing-label address (not the raw ship_* keys).
        self::assertStringContainsString('Ana Anić', $html);
        self::assertStringContainsString('10000 Zagreb', $html, 'postal + city on one line (formatted)');
        self::assertStringNotContainsString('ship_name', $html, 'raw address keys must not leak into the detail');

        // No Merchant-of-Record section / Dodo-only fields on a Stripe order.
        self::assertStringNotContainsString('Merchant of Record', $html);
        self::assertStringNotContainsString('Licence key', $html);
        self::assertStringNotContainsString('Customer phone', $html);
    }

    public function testDodoOrderShowsMerchantOfRecordSection(): void
    {
        $client = static::createClient();
        $order = $this->seedOrder('dodo', shipped: false);
        $client->loginUser($this->makeAdmin());

        $html = $this->detail($client, $order);

        self::assertStringContainsString('Merchant of Record', $html, 'a Dodo order shows the MoR panel');
        self::assertStringContainsString('Licence key', $html);
    }

    private function detail(KernelBrowser $client, Order $order): string
    {
        // The legacy query-param URL 302-redirects to EA's canonical /admin/order/{id} — follow it.
        $client->followRedirects(true);
        $client->request('GET', '/admin', [
            'crudControllerFqcn' => OrderCrudController::class,
            'crudAction' => 'detail',
            'entityId' => $order->getId(),
        ]);
        self::assertResponseIsSuccessful();

        return (string) $client->getResponse()->getContent();
    }

    private function seedOrder(string $provider, bool $shipped): Order
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        if (null === $this->formId) {
            $form = (new FormDefinition())->setName('Detail test')->setSlug('detail-test-'.bin2hex(random_bytes(4)));
            $em->persist($form);
            $em->flush();
            $this->formId = $form->getId();
        } else {
            $form = $em->getRepository(FormDefinition::class)->find($this->formId);
        }

        $submission = (new FormSubmission())->setForm($form)->setData($shipped ? [
            'ship_name' => 'Ana Anić',
            'ship_address' => 'Ilica 1',
            'ship_postal' => '10000',
            'ship_city' => 'Zagreb',
            'ship_country' => 'Hrvatska',
        ] : ['note' => 'digital']);
        $em->persist($submission);
        $em->flush();
        $this->submissionId = $submission->getId();

        $order = (new Order())
            ->setForm($form)
            ->setSubmission($submission)
            ->setStatus(Order::STATUS_PAID)
            ->setProvider($provider)
            ->setPaymentMode('test')
            ->setAmountMinor($shipped ? 3200 : 2900)
            ->setCurrency('eur')
            ->setCustomerEmail('buyer@t.local');
        if ($shipped) {
            $order->setShippingLabel('Express')->setShippingAmountMinor(1200)
                ->setNetAmountMinor(2560)->setTaxAmountMinor(640)->setTaxRate('25')->setTaxName('PDV');
        }
        if ('dodo' === $provider) {
            $order->setLicenseKey('LIC-123')->setDodoTaxMinor(500);
        }
        $em->persist($order);
        $em->flush();
        $this->orderIds[] = $order->getId();

        return $order;
    }

    private function makeAdmin(): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $email = 'order_detail_'.bin2hex(random_bytes(6)).'@test.local';
        $user = (new User($email))->setRoles(['ROLE_ADMIN']);
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $em->persist($user);
        $em->flush();
        $this->emails[] = $email;

        return $user;
    }

    protected function tearDown(): void
    {
        /** @var Connection $conn */
        $conn = static::getContainer()->get(Connection::class);
        foreach ($this->orderIds as $id) {
            $conn->executeStatement('DELETE FROM fb_order WHERE id = ?', [$id]);
        }
        if (null !== $this->submissionId) {
            $conn->executeStatement('DELETE FROM fb_submission WHERE form_id = ?', [$this->formId]);
        }
        if (null !== $this->formId) {
            $conn->executeStatement('DELETE FROM fb_form WHERE id = ?', [$this->formId]);
        }
        foreach ($this->emails as $email) {
            $conn->executeStatement('DELETE FROM user WHERE email = ?', [$email]);
        }
        $this->orderIds = [];
        $this->formId = $this->submissionId = null;
        $this->emails = [];

        parent::tearDown();
    }
}
