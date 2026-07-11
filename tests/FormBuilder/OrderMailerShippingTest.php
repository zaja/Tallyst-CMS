<?php

namespace App\Tests\FormBuilder;

use App\Email\EmailSender;
use App\Settings\SettingsManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tallyst\FormBuilder\Entity\FormSubmission;
use Tallyst\FormBuilder\Entity\Order;
use Tallyst\FormBuilder\Service\OrderMailer;

/**
 * Hard point #6 (visibility for MANUAL fulfilment): the admin notice must carry the chosen delivery
 * method + amount AND the buyer's delivery address — in the composed `delivery_details` block — plus the
 * OTHER form fields in `form_data` (address excluded). A digital/MoR order (no shipping) leaves the block
 * empty (mail unchanged). Uses the real translator so the composed block reflects the actual template.
 */
class OrderMailerShippingTest extends KernelTestCase
{
    /** @param array<string, array<string, string>> $sent captured tags per email type (by ref) */
    private function mailer(array &$sent): OrderMailer
    {
        $c = static::getContainer();
        $emails = $this->createStub(EmailSender::class);
        $emails->method('send')->willReturnCallback(function (string $type, array $tags) use (&$sent): void {
            $sent[$type] = $tags;
        });

        return new OrderMailer(
            $emails,
            $c->get(SettingsManager::class),
            $c->get(TranslatorInterface::class),
            'env-admin@shop.test',
        );
    }

    public function testAdminNoticeCarriesDeliveryAndFormattedAddress(): void
    {
        self::bootKernel();
        $sent = [];
        $mailer = $this->mailer($sent);

        $submission = (new FormSubmission())->setData([
            'ship_name' => 'Ana Anić',
            'ship_address' => 'Ilica 1',
            'ship_postal' => '10000',
            'ship_city' => 'Zagreb',
            'ship_country' => 'Hrvatska',
            'note' => 'ring the bell',
        ]);
        $order = (new Order())
            ->setSubmission($submission)
            ->setAmountMinor(3200)
            ->setCurrency('eur')
            ->setShippingLabel('Express')
            ->setShippingAmountMinor(1200)
            ->setNetAmountMinor(2560)->setTaxAmountMinor(640)->setTaxRate('25')->setTaxName('PDV');

        $mailer->sendAdminNotice($order);

        $tags = $sent['order_admin'] ?? [];
        // The delivery_details block: method + amount + the FORMATTED (multi-line) address.
        self::assertStringContainsString('Express', $tags['delivery_details']);
        self::assertStringContainsString('12,00 EUR', $tags['delivery_details']);
        self::assertStringContainsString('Ana Anić', $tags['delivery_details']);
        self::assertStringContainsString('10000 Zagreb', $tags['delivery_details'], 'postal + city on one line');
        self::assertStringContainsString('PDV', $tags['delivery_details'], 'inclusive tax line present');
        self::assertDoesNotMatchRegularExpression('/%[a-z_]+%/', $tags['delivery_details'], 'no leftover template placeholders');
        // form_data has the OTHER fields but NOT the ship_* address (shown formatted above).
        self::assertStringContainsString('note: ring the bell', $tags['form_data']);
        self::assertStringNotContainsString('ship_name', $tags['form_data']);
    }

    public function testDigitalOrderLeavesDeliveryBlockEmpty(): void
    {
        self::bootKernel();
        $sent = [];
        $mailer = $this->mailer($sent);

        $order = (new Order())->setAmountMinor(2900)->setCurrency('eur'); // digital / MoR — no shipping

        $mailer->sendAdminNotice($order);

        self::assertSame('', $sent['order_admin']['delivery_details'], 'no delivery → empty block (mail unchanged)');
        self::assertSame('—', $sent['order_admin']['shipping']);
    }

    public function testCustomerConfirmationGetsDeliveryDetails(): void
    {
        self::bootKernel();
        $sent = [];
        $mailer = $this->mailer($sent);

        $submission = (new FormSubmission())->setData(['ship_name' => 'Ana Anić', 'ship_city' => 'Zagreb']);
        $order = (new Order())
            ->setSubmission($submission)
            ->setCustomerEmail('buyer@t.local')
            ->setAmountMinor(3200)->setCurrency('eur')
            ->setShippingLabel('Express')->setShippingAmountMinor(1200);

        $mailer->sendConfirmation($order);

        self::assertArrayHasKey('order_confirmation', $sent);
        self::assertStringContainsString('Ana Anić', $sent['order_confirmation']['delivery_details']);
        self::assertStringContainsString('Express', $sent['order_confirmation']['delivery_details']);
    }
}
