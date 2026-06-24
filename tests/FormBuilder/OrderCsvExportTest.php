<?php

namespace App\Tests\FormBuilder;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tallyst\FormBuilder\Controller\Admin\OrderCrudController;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\FormSubmission;
use Tallyst\FormBuilder\Entity\Order;

/**
 * The accountant CSV (a) carries the buyer's form data on one safely-quoted line, and (b) RESPECTS the
 * active list filter — exporting exactly what the filtered list shows (no filter → all). The export reuses
 * EA's createIndexQueryBuilder over the request's SearchDto, so list query == export query.
 */
class OrderCsvExportTest extends WebTestCase
{
    /** @var int[] */
    private array $orderIds = [];
    private ?int $formId = null;
    private ?int $submissionId = null;
    private array $emails = [];

    public function testExportRespectsActiveFilterAndQuotesCustomerData(): void
    {
        $client = static::createClient();
        $this->seed();
        $client->loginUser($this->makeAdmin());

        // No filter → ALL seeded orders, and the comma+newline submission stays one RFC-quoted cell.
        $all = $this->export($client);
        self::assertStringContainsString('paid-stripe@t.local', $all);
        self::assertStringContainsString('pending-paypal@t.local', $all);
        self::assertStringContainsString('paid-paypal@t.local', $all);
        self::assertStringContainsString('Podaci kupca', $all, 'header column present');
        self::assertStringContainsString('Mod', $all);
        self::assertStringNotContainsString('Zemlja', $all);
        self::assertStringNotContainsString('VAT ID', $all);
        self::assertStringContainsString('"ime: Goran Zajec; tvrtka: Sve je dobro, j.d.o.o."', $all, 'newlines flattened + comma value RFC-quoted into one cell');

        // status = paid → only the two paid orders, never the pending one.
        $paid = $this->export($client, ['status' => ['comparison' => '=', 'value' => Order::STATUS_PAID]]);
        self::assertStringContainsString('paid-stripe@t.local', $paid);
        self::assertStringContainsString('paid-paypal@t.local', $paid);
        self::assertStringNotContainsString('pending-paypal@t.local', $paid, 'pending order excluded by the status filter');

        // provider = stripe → only the stripe order.
        $stripe = $this->export($client, ['provider' => ['comparison' => '=', 'value' => 'stripe']]);
        self::assertStringContainsString('paid-stripe@t.local', $stripe);
        self::assertStringNotContainsString('paypal@t.local', $stripe, 'paypal orders excluded by the provider filter');
    }

    private function export(KernelBrowser $client, array $filters = []): string
    {
        $params = ['crudControllerFqcn' => OrderCrudController::class, 'crudAction' => 'exportCsv'];
        if ([] !== $filters) {
            $params['filters'] = $filters;
        }
        $client->request('GET', '/admin', $params);
        self::assertResponseIsSuccessful();

        // The test client (HttpKernelBrowser) already buffers a StreamedResponse into the BrowserKit
        // response, so read it from there (the Symfony response is already streamed → empty).
        return (string) $client->getInternalResponse()->getContent();
    }

    private function seed(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $form = (new FormDefinition())->setName('Export test')->setSlug('export-test-'.bin2hex(random_bytes(4)));
        $em->persist($form);
        $em->flush();
        $this->formId = $form->getId();

        $submission = (new FormSubmission())->setForm($form)->setData([
            'ime' => 'Goran Zajec',
            'tvrtka' => 'Sve je dobro, j.d.o.o.',
        ]);
        $em->persist($submission);
        $em->flush();
        $this->submissionId = $submission->getId();

        $rows = [
            ['paid-stripe@t.local', Order::STATUS_PAID, 'stripe', $submission],
            ['pending-paypal@t.local', Order::STATUS_PENDING, 'paypal', null],
            ['paid-paypal@t.local', Order::STATUS_PAID, 'paypal', null],
        ];
        foreach ($rows as [$email, $status, $provider, $sub]) {
            $order = (new Order())
                ->setForm($form)
                ->setSubmission($sub)
                ->setStatus($status)
                ->setProvider($provider)
                ->setPaymentMode('test')
                ->setAmountMinor(4900)
                ->setCurrency('eur')
                ->setCustomerEmail($email);
            $em->persist($order);
            $em->flush();
            $this->orderIds[] = $order->getId();
        }
    }

    private function makeAdmin(): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $email = 'order_export_'.bin2hex(random_bytes(6)).'@test.local';
        $user = (new User($email))->setRoles(['ROLE_ADMIN']);
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $em->persist($user);
        $em->flush();
        $this->emails[] = $email;

        return $user;
    }

    protected function tearDown(): void
    {
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        foreach ($this->orderIds as $id) {
            $conn->executeStatement('DELETE FROM fb_order WHERE id = ?', [$id]);
        }
        if (null !== $this->submissionId) {
            $conn->executeStatement('DELETE FROM fb_submission WHERE id = ?', [$this->submissionId]);
        }
        if (null !== $this->formId) {
            $conn->executeStatement('DELETE FROM fb_form WHERE id = ?', [$this->formId]);
        }
        foreach ($this->emails as $email) {
            $conn->executeStatement('DELETE FROM user WHERE email = ?', [$email]);
        }

        parent::tearDown();
    }
}
