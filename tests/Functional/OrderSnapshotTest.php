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
use Tallyst\FormBuilder\Entity\FormType;
use Tallyst\FormBuilder\Entity\Order;

/**
 * An order is a historical record: once the money has moved, nothing done to the form afterwards may
 * change what that order says was sold, or take the buyer's details away with it.
 *
 * These are the guarantees nobody was asserting before. Both of the things they protect against are
 * ordinary shop-owner actions, not edge cases — renaming a product, and removing a form that is no
 * longer sold (which the admin UI blocks, but the demo uninstaller and any direct em->remove() do not).
 *
 * Needs the test DB (see AdminAccessTest): doctrine:database:create --env=test +
 * doctrine:migrations:migrate --env=test.
 */
class OrderSnapshotTest extends WebTestCase
{
    /** @var int[] */
    private array $orderIds = [];
    /** @var int[] */
    private array $formIds = [];
    /** @var int[] */
    private array $submissionIds = [];
    /** @var string[] */
    private array $emails = [];

    /**
     * (a) Renaming a form must not rewrite history. The assertion is made after a clear()+reload, so it
     * is about what is stored, not about a stale object still holding the old string.
     */
    public function testRenamingAFormDoesNotChangeWhatAnOldOrderSaysItSold(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $form = $this->makeForm($em, 'Arca Pro');
        $order = $this->makeOrder($em, $form, productName: 'Arca Pro');
        $orderId = $order->getId();

        // The shop owner renames the product.
        $form->setName('Arca Ultimate 2027');
        $em->flush();
        $em->clear();

        $reloaded = $em->getRepository(Order::class)->find($orderId);
        self::assertSame('Arca Pro', $reloaded->getProductName(), 'the stored snapshot must be untouched');
        self::assertSame('Arca Pro', $reloaded->getProductLabel(), 'and the displayed name must come from it');
        self::assertSame(
            'Arca Ultimate 2027',
            $reloaded->getSourceFormLabel(),
            'while the origin line still tracks the live form — that one is meant to move'
        );
    }

    /**
     * (c) Deleting the form outright — straight through the entity manager, exactly as the demo
     * uninstaller does, bypassing FormDeletionGuard which only protects the admin action. Before this
     * work the database CASCADE deleted the orders here; now they survive intact.
     */
    public function testDeletingAFormDirectlyLeavesTheOrderAndItsSnapshotsIntact(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $form = $this->makeForm($em, 'Arca Pro');
        $submission = $this->makeSubmission($em, $form, ['ship_name' => 'Ana Anić', 'ship_city' => 'Zagreb', 'note' => 'ring the bell']);
        $order = $this->makeOrder($em, $form, productName: 'Arca Pro', submission: $submission);
        $orderId = $order->getId();
        $submissionId = $submission->getId();

        // No guard, no controller — the raw deletion the database used to answer with CASCADE.
        $em->remove($form);
        $em->flush();
        $em->clear();

        $reloaded = $em->getRepository(Order::class)->find($orderId);
        self::assertNotNull($reloaded, 'the order must survive the deletion of its form');
        self::assertNull($reloaded->getForm(), 'its link to the form is severed (SET NULL), not fatal');
        self::assertNull(
            $em->getRepository(FormSubmission::class)->find($submissionId),
            'the submission still goes with the form (unchanged CASCADE) — which is exactly why the snapshot exists'
        );
        self::assertNull($reloaded->getSubmission(), 'and the order no longer points at it');

        // Everything the admin needs is still on the order itself.
        self::assertSame('Arca Pro', $reloaded->getProductLabel(), 'what was sold');
        self::assertSame(2900, $reloaded->getAmountMinor(), 'for how much');
        self::assertStringContainsString('note: ring the bell', $reloaded->getFormDataSummary(), 'who bought it');
        self::assertStringContainsString('Ana Anić', $reloaded->getShippingAddressFormatted(), 'where it goes');
        self::assertSame('—', $reloaded->getSourceFormLabel(), 'only the origin is gone, and it says so plainly');
    }

    /**
     * (d) The same order, seen through the admin: the detail page must show the buyer's data from the
     * snapshot rather than the blank panels a missing submission used to produce.
     */
    public function testOrderDetailShowsProductAndBuyerDataWhenFormAndSubmissionAreGone(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $form = $this->makeForm($em, 'Arca Pro');
        $submission = $this->makeSubmission($em, $form, [
            'ship_name' => 'Ana Anić',
            'ship_address' => 'Ilica 1',
            'ship_postal' => '10000',
            'ship_city' => 'Zagreb',
            'note' => 'ring the bell',
        ]);
        $order = $this->makeOrder($em, $form, productName: 'Arca Pro', submission: $submission, shipped: true);
        $orderId = $order->getId();

        $em->remove($form);
        $em->flush();
        $em->clear();

        $client->loginUser($this->makeAdmin());
        $html = $this->detail($client, $orderId);

        self::assertStringContainsString('Arca Pro', $html, 'the product name must still be on the page');
        self::assertStringContainsString('ring the bell', $html, 'the buyer\'s form data must still be on the page');
        self::assertStringContainsString('Ana Anić', $html, 'and the delivery address');
        self::assertStringContainsString('10000 Zagreb', $html, 'still formatted as a mailing label');
        self::assertStringNotContainsString('ship_name', $html, 'raw address keys must not leak into the detail');
    }

    /** The index must show the product too — the column an admin scans is now snapshot-backed. */
    public function testOrderIndexShowsTheSnapshottedProductAfterTheFormIsRenamed(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $form = $this->makeForm($em, 'Arca Pro');
        $this->makeOrder($em, $form, productName: 'Arca Pro');

        $form->setName('Arca Ultimate 2027');
        $em->flush();
        $em->clear();

        $client->loginUser($this->makeAdmin());
        $client->followRedirects(true);
        $client->request('GET', '/admin', ['crudControllerFqcn' => OrderCrudController::class, 'crudAction' => 'index']);
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Arca Pro', $html, 'the list shows what was sold');
        self::assertStringNotContainsString('Arca Ultimate 2027', $html, 'not what the form is called now');
    }

    // --- fixtures -----------------------------------------------------------------------------------

    private function detail(KernelBrowser $client, int $orderId): string
    {
        // The legacy query-param URL 302-redirects to EA's canonical /admin/order/{id} — follow it.
        $client->followRedirects(true);
        $client->request('GET', '/admin', [
            'crudControllerFqcn' => OrderCrudController::class,
            'crudAction' => 'detail',
            'entityId' => $orderId,
        ]);
        self::assertResponseIsSuccessful();

        return (string) $client->getResponse()->getContent();
    }

    private function makeForm(EntityManagerInterface $em, string $name): FormDefinition
    {
        $form = (new FormDefinition())
            ->setName($name)
            ->setSlug('snapshot-'.bin2hex(random_bytes(5)))
            ->setStatus(FormDefinition::STATUS_PUBLISHED)
            ->setFormType(FormType::DIGITAL)
            ->setPriceMinor(2900)
            ->setCurrency('eur');

        $em->persist($form);
        $em->flush();
        $this->formIds[] = $form->getId();

        return $form;
    }

    /** @param array<string, mixed> $data */
    private function makeSubmission(EntityManagerInterface $em, FormDefinition $form, array $data): FormSubmission
    {
        $submission = (new FormSubmission())->setForm($form)->setData($data);
        $em->persist($submission);
        $em->flush();
        $this->submissionIds[] = $submission->getId();

        return $submission;
    }

    private function makeOrder(
        EntityManagerInterface $em,
        FormDefinition $form,
        string $productName,
        ?FormSubmission $submission = null,
        bool $shipped = false,
    ): Order {
        $order = (new Order())
            ->setForm($form)
            ->setSubmission($submission)
            ->setProductName($productName)
            ->setSubmissionData($submission?->getData())
            ->setStatus(Order::STATUS_PAID)
            ->setProvider('stripe')
            ->setPaymentMode('test')
            ->setAmountMinor(2900)
            ->setCurrency('eur')
            ->setCustomerEmail('buyer@t.local');

        if ($shipped) {
            $order->setShippingLabel('Express')->setShippingAmountMinor(300);
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

        $email = 'order_snapshot_'.bin2hex(random_bytes(6)).'@test.local';
        $user = (new User($email))->setRoles(['ROLE_ADMIN']);
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $em->persist($user);
        $em->flush();
        $this->emails[] = $email;

        return $user;
    }

    /** Raw SQL, child-first — the fixtures deliberately leave severed FKs behind. */
    protected function tearDown(): void
    {
        /** @var Connection $conn */
        $conn = static::getContainer()->get(Connection::class);
        foreach ($this->orderIds as $id) {
            $conn->executeStatement('DELETE FROM fb_order WHERE id = ?', [$id]);
        }
        foreach ($this->submissionIds as $id) {
            $conn->executeStatement('DELETE FROM fb_submission WHERE id = ?', [$id]);
        }
        foreach ($this->formIds as $id) {
            $conn->executeStatement('DELETE FROM fb_form WHERE id = ?', [$id]);
        }
        foreach ($this->emails as $email) {
            $conn->executeStatement('DELETE FROM `user` WHERE email = ?', [$email]);
        }

        $this->orderIds = $this->formIds = $this->submissionIds = [];
        $this->emails = [];

        parent::tearDown();
    }
}
