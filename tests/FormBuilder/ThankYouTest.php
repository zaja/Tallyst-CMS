<?php

namespace App\Tests\FormBuilder;

use App\Settings\SettingsManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\Order;

/**
 * Thank-you page: editable message + dynamic order block, gated by an unguessable token (anti-enumeration).
 * Uses the Stripe provider so finalizeReturn() is a no-op (no network).
 */
class ThankYouTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $token;
    private int $orderId;
    private int $nullTokenOrderId;
    private int $formId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->token = bin2hex(random_bytes(16));
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $form = (new FormDefinition())->setName('TY')->setSlug('ty-'.bin2hex(random_bytes(4)));
        $em->persist($form);
        $em->flush();
        $this->formId = $form->getId();

        $order = (new Order())->setForm($form)->setProvider('stripe')->setAmountMinor(4900)->setCurrency('eur')
            ->setThankYouToken($this->token);
        $em->persist($order);
        $em->flush();
        $this->orderId = $order->getId();

        $nullOrder = (new Order())->setForm($form)->setProvider('stripe')->setAmountMinor(100)->setCurrency('eur');
        $em->persist($nullOrder);
        $em->flush();
        $this->nullTokenOrderId = $nullOrder->getId();
    }

    public function testCorrectTokenShowsDefaultMessageAndOrderBlock(): void
    {
        $this->client->request('GET', '/form/order/'.$this->orderId.'/thank-you', ['t' => $this->token]);

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Potvrdu i upute', $body, 'default thank-you message');
        self::assertStringContainsString('#'.$this->orderId, $body, 'dynamic order id');
        self::assertStringContainsString('49,00', $body, 'dynamic amount');
    }

    public function testCustomMessageRendered(): void
    {
        self::getContainer()->get(SettingsManager::class)->set('thank_you_message', '<p>MOJA-ZAHVALA-XYZ</p>');
        $this->client->request('GET', '/form/order/'.$this->orderId.'/thank-you', ['t' => $this->token]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('MOJA-ZAHVALA-XYZ', (string) $this->client->getResponse()->getContent());
    }

    public function testWrongTokenIs404(): void
    {
        $this->client->request('GET', '/form/order/'.$this->orderId.'/thank-you', ['t' => 'wrong'.$this->token]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testMissingTokenIs404(): void
    {
        $this->client->request('GET', '/form/order/'.$this->orderId.'/thank-you');
        self::assertResponseStatusCodeSame(404);
    }

    public function testNullTokenOrderIs404(): void
    {
        $this->client->request('GET', '/form/order/'.$this->nullTokenOrderId.'/thank-you', ['t' => $this->token]);
        self::assertResponseStatusCodeSame(404);
    }

    protected function tearDown(): void
    {
        $conn = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $conn->executeStatement('DELETE FROM fb_order WHERE id IN (?, ?)', [$this->orderId, $this->nullTokenOrderId]);
        $conn->executeStatement('DELETE FROM fb_form WHERE id = ?', [$this->formId]);
        $conn->executeStatement("DELETE FROM setting WHERE name = 'thank_you_message'");
        parent::tearDown();
    }
}
