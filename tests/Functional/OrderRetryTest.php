<?php

namespace App\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\Order;

/**
 * Offering a buyer their unfinished checkout back.
 *
 * ⚠ THE RULE THIS PROTECTS: a buyer's click is never evidence of payment. Pressing "try again" starts
 * a NEW purchase and leaves the old order closed, which is what lets the owner keep seeing how many
 * people drop out and how many come back. It is also why a late provider confirmation MAY reopen an
 * order while this never can — status follows what the provider asserts, not what a visitor does.
 */
class OrderRetryTest extends WebTestCase
{
    /** @var int[] */
    private array $orderIds = [];
    private ?int $formId = null;

    protected function tearDown(): void
    {
        $conn = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        foreach ($this->orderIds as $id) {
            $conn->executeStatement('DELETE FROM fb_order WHERE id = ?', [$id]);
        }
        if (null !== $this->formId) {
            $conn->executeStatement('DELETE FROM fb_form WHERE id = ?', [$this->formId]);
        }
        $this->orderIds = [];
        $this->formId = null;
        parent::tearDown();
    }

    private function form(KernelBrowser $client): FormDefinition
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $form = (new FormDefinition())->setName('Arca Backup')->setSlug('retry-'.uniqid());
        $em->persist($form);
        $em->flush();
        $this->formId = $form->getId();

        return $form;
    }

    private function failedOrder(KernelBrowser $client, ?FormDefinition $form, ?string $returnPath = '/buy-arca'): Order
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $order = (new Order())
            ->setForm($form)
            ->setProductName('Arca Backup')
            ->setAmountMinor(3625)
            ->setCurrency('eur')
            ->setStatus(Order::STATUS_FAILED)
            ->setAbandonedAt(new \DateTimeImmutable())
            ->setSubmissionData(['full_name' => 'Pero Perić', 'company' => 'Sve je dobro'])
            ->setThankYouToken(bin2hex(random_bytes(16)))
            ->setReturnPath($returnPath);
        $em->persist($order);
        $em->flush();
        $this->orderIds[] = $order->getId();

        return $order;
    }

    private function retryUrl(Order $order): string
    {
        return '/form/order/'.$order->getId().'/retry?t='.$order->getThankYouToken();
    }

    /**
     * ⚠ THE ADDRESS MUST BE ON THE ORDER BEFORE ANY PAYMENT HAPPENS, or the whole
     * "your purchase wasn't completed" mail is dead code.
     *
     * Measured before this was fixed: customerEmail was written ONLY by the paid webhook, so every
     * checkout that was never completed had no address — all five on the dev database. The feature
     * would have shipped looking correct and reaching nobody.
     */
    public function testTheBuyersAddressIsCapturedFromTheFormNotOnlyFromThePayment(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $form = $this->form($client);
        $field = (new \Tallyst\FormBuilder\Entity\FormField())
            ->setForm($form)
            ->setType(\Tallyst\FormBuilder\Entity\FormField::TYPE_EMAIL)
            ->setKey('email')
            ->setLabel('E-mail');
        $em->persist($field);
        $em->flush();
        // Doctrine does not sync the inverse side in memory — without this the form's field
        // collection is still empty and the helper would look correct while seeing nothing.
        $em->refresh($form);

        $submission = (new \Tallyst\FormBuilder\Entity\FormSubmission())
            ->setForm($form)
            ->setData(['email' => 'buyer@example.com', 'full_name' => 'Pero Perić']);
        $em->persist($submission);
        $em->flush();

        $controller = static::getContainer()->get(\Tallyst\FormBuilder\Controller\FormSubmitController::class);
        $method = new \ReflectionMethod($controller, 'emailFromSubmission');

        self::assertSame('buyer@example.com', $method->invoke($controller, $form, $submission));

        $em->remove($submission);
        $em->remove($field);
        $em->flush();
    }

    /** ⚠ THE ONE THAT MATTERS: the buyer lands back on the page they started from. */
    public function testItSendsTheBuyerBackToThePageTheStartedFrom(): void
    {
        $client = static::createClient();
        $order = $this->failedOrder($client, $this->form($client));

        $client->request('GET', $this->retryUrl($order));

        self::assertResponseRedirects('/buy-arca');
    }

    /** ⚠ And the old order is untouched — a click is not a payment. */
    public function testTheOldOrderIsNotChanged(): void
    {
        $client = static::createClient();
        $order = $this->failedOrder($client, $this->form($client));

        $client->request('GET', $this->retryUrl($order));

        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->getRepository(Order::class)->find($order->getId());

        self::assertTrue($fresh->isFailed(), 'it stays counted among the checkouts this shop lost');
        self::assertTrue($fresh->wasAbandoned());
    }

    /**
     * ⚠ GUARDED BY THE ORDER'S OWN TOKEN. Without it, walking the ids would load a stranger's
     * submitted details — name, address, VAT number — into your own form. Same hole the thank-you
     * page closed, and the same guard.
     */
    public function testAWrongOrMissingTokenIsRefused(): void
    {
        $client = static::createClient();
        $order = $this->failedOrder($client, $this->form($client));

        $client->request('GET', '/form/order/'.$order->getId().'/retry?t='.str_repeat('0', 32));
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/form/order/'.$order->getId().'/retry');
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * ⚠ An order outlives the form and the page it came from, by design. There is then nothing to
     * resume, so the buyer goes to the front page rather than to an error — and the button is not
     * offered in the first place.
     */
    public function testWithNothingToReturnToItFallsBackToTheFrontPage(): void
    {
        $client = static::createClient();

        $noPath = $this->failedOrder($client, $this->form($client), null);
        $client->request('GET', $this->retryUrl($noPath));
        self::assertResponseRedirects('/');

        $noForm = $this->failedOrder($client, null);
        $client->request('GET', $this->retryUrl($noForm));
        self::assertResponseRedirects('/');
    }

    /**
     * ⚠ An absolute or protocol-relative return path must never survive to become a link in an
     * e-mail sent under the shop's name — that is an open redirect posted to the buyer.
     */
    public function testAnOffsiteReturnPathIsNeverStored(): void
    {
        $client = static::createClient();

        foreach (['https://evil.example/checkout', '//evil.example/checkout', 'javascript:alert(1)'] as $hostile) {
            $order = $this->failedOrder($client, $this->form($client), $hostile);

            self::assertNull($order->getReturnPath(), $hostile.' must not be stored');
        }
    }
}
