<?php

namespace App\Tests\FormBuilder;

use App\Email\EmailSender;
use App\Settings\SettingsManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\FormSubmission;
use Tallyst\FormBuilder\Entity\Order;
use Tallyst\FormBuilder\Service\OrderMailer;

/**
 * An order mail must describe the purchase AS IT WAS, not as the shop looks today.
 *
 * The order mails are not all sent at checkout: "delivered" goes out when the admin ships, and
 * "refunded" can go out months later. Until the snapshot columns existed, every one of them read the
 * product name off the LIVE form, so renaming a product silently rewrote what old orders claimed the
 * customer had bought, and deleting the form left "-" on mails for orders that were perfectly intact.
 *
 * Same for the buyer's own data: it lived only in FormSubmission, which is CASCADE-deleted with the
 * form, so the admin notice for a real sale could arrive with an empty "form_data" block.
 */
class OrderSnapshotMailTest extends KernelTestCase
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

    /**
     * The headline case: a refund mail is the one that goes out longest after the sale, so it is the one
     * most likely to be sent after the product has been renamed.
     */
    public function testRefundMailCarriesTheNameTheProductHadWhenItWasSold(): void
    {
        self::bootKernel();
        $sent = [];
        $mailer = $this->mailer($sent);

        // Sold as "Arca Pro" — the snapshot is written at checkout.
        $form = (new FormDefinition())->setName('Arca Pro');
        $order = (new Order())
            ->setForm($form)
            ->setProductName('Arca Pro')
            ->setCustomerEmail('buyer@t.local')
            ->setAmountMinor(2900)->setCurrency('eur');

        // Months later the shop owner renames the product, then refunds that old order.
        $form->setName('Arca Ultimate 2027');

        $mailer->sendRefunded($order);

        self::assertSame(
            'Arca Pro',
            $sent['order_refunded']['form_name'],
            'the refund mail must name what the customer actually bought, not what the form is called today'
        );
    }

    /** All four order mails share OrderMailer::tags(), so all four must be on the snapshot. */
    public function testEveryOrderMailUsesTheSnapshotNotTheLiveForm(): void
    {
        self::bootKernel();
        $sent = [];
        $mailer = $this->mailer($sent);

        $form = (new FormDefinition())->setName('RENAMED LATER');
        $order = (new Order())
            ->setForm($form)
            ->setProductName('Arca Pro')
            ->setCustomerEmail('buyer@t.local')
            ->setAmountMinor(2900)->setCurrency('eur');

        $mailer->sendConfirmation($order);
        $mailer->sendAdminNotice($order);
        $mailer->sendDelivered($order);
        $mailer->sendRefunded($order);

        foreach (['order_confirmation', 'order_admin', 'order_delivered', 'order_refunded'] as $type) {
            self::assertSame('Arca Pro', $sent[$type]['form_name'], "$type must carry the snapshotted product name");
        }
    }

    /**
     * The belt for orders older than the snapshot column: with no snapshot the mail falls back to the
     * live form rather than losing the name entirely, and to '-' when even that is gone.
     */
    public function testMailFallsBackToTheLiveFormAndThenToADashWhenThereIsNoSnapshot(): void
    {
        self::bootKernel();
        $sent = [];
        $mailer = $this->mailer($sent);

        $withForm = (new Order())
            ->setForm((new FormDefinition())->setName('Legacy product'))
            ->setCustomerEmail('buyer@t.local')->setAmountMinor(1000)->setCurrency('eur');
        $mailer->sendConfirmation($withForm);
        self::assertSame('Legacy product', $sent['order_confirmation']['form_name']);

        $sent = [];
        $orphan = (new Order())->setCustomerEmail('buyer@t.local')->setAmountMinor(1000)->setCurrency('eur');
        $mailer->sendConfirmation($orphan);
        self::assertSame('-', $sent['order_confirmation']['form_name'], 'no snapshot and no form → the old placeholder');
    }

    /**
     * The submission is gone (its form was deleted, CASCADE took it, and fb_order.submission_id was
     * SET NULL) but the order still has to tell the admin who bought what and where to ship it.
     */
    public function testBuyerDataInMailsComesFromTheSnapshotWhenTheSubmissionIsGone(): void
    {
        self::bootKernel();
        $sent = [];
        $mailer = $this->mailer($sent);

        $order = (new Order())
            ->setSubmission(null) // the submission no longer exists
            ->setProductName('Arca Pro')
            ->setSubmissionData([
                'ship_name' => 'Ana Anić',
                'ship_address' => 'Ilica 1',
                'ship_postal' => '10000',
                'ship_city' => 'Zagreb',
                'ship_country' => 'Hrvatska',
                'note' => 'ring the bell',
            ])
            ->setCustomerEmail('buyer@t.local')
            ->setAmountMinor(3200)->setCurrency('eur')
            ->setShippingLabel('Express')->setShippingAmountMinor(1200);

        $mailer->sendAdminNotice($order);
        $mailer->sendConfirmation($order);

        // The admin still gets the buyer's other fields...
        self::assertStringContainsString('note: ring the bell', $sent['order_admin']['form_data']);
        self::assertStringNotContainsString('ship_name', $sent['order_admin']['form_data'], 'address still excluded here');
        // ...and the full dump for custom templates.
        self::assertStringContainsString('ship_city: Zagreb', $sent['order_admin']['submission_summary']);
        // ...and both mails still carry the formatted mailing label, which is what makes manual
        // fulfilment possible at all.
        foreach (['order_admin', 'order_confirmation'] as $type) {
            self::assertStringContainsString('Ana Anić', $sent[$type]['delivery_details'], "$type address line");
            self::assertStringContainsString('10000 Zagreb', $sent[$type]['delivery_details'], "$type postal + city");
        }
    }

    /**
     * An EMPTY snapshot is a real answer ("the form had no fields"), not a missing one — it must not
     * fall through to a live submission that happens to still be attached.
     */
    public function testAnEmptySnapshotIsNotTreatedAsMissing(): void
    {
        self::bootKernel();
        $sent = [];
        $mailer = $this->mailer($sent);

        $order = (new Order())
            ->setSubmission((new FormSubmission())->setData(['note' => 'from the live submission']))
            ->setSubmissionData([]) // recorded at checkout: nothing was submitted
            ->setCustomerEmail('buyer@t.local')->setAmountMinor(2900)->setCurrency('eur');

        $mailer->sendAdminNotice($order);

        self::assertSame('', $sent['order_admin']['form_data'], 'the empty snapshot wins over the live submission');
    }
}
