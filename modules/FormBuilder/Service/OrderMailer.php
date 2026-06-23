<?php

namespace Tallyst\FormBuilder\Service;

use App\Email\EmailSender;
use App\Settings\SettingsManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Tallyst\FormBuilder\Entity\Order;

/**
 * One place that builds + sends the order e-mails (through the editable email engine). Used by
 * BOTH the async fulfillment handler (automatic, on payment) AND the admin "resend confirmation"
 * / "mark delivered" actions — so a resend is byte-for-byte the same mail as the original, and the
 * tag/recipient logic can't drift between the two call sites.
 *
 * From is left unset (DefaultFromListener / 553 lesson) and sends are async — both handled by
 * EmailSender.
 */
class OrderMailer
{
    public function __construct(
        private readonly EmailSender $emails,
        private readonly SettingsManager $settings,
        #[Autowire('%env(ORDER_ADMIN_EMAIL)%')]
        private readonly string $adminEmail,
    ) {
    }

    /** Customer confirmation ("paid"). No-op if the order has no customer e-mail. */
    public function sendConfirmation(Order $order): void
    {
        $email = $order->getCustomerEmail();
        if (null === $email || '' === $email) {
            return;
        }
        $this->emails->send('order_confirmation', $this->tags($order), $email);
    }

    /** Admin notice of a new paid order → the order_admin_email setting, else the env fallback. */
    public function sendAdminNotice(Order $order): void
    {
        $to = ((string) $this->settings->get('order_admin_email')) ?: $this->adminEmail;
        $this->emails->send('order_admin', $this->tags($order) + [
            'customer_email' => $order->getCustomerEmail() ?? '-',
        ], $to);
    }

    /** Customer "your order was delivered", sent when the admin marks it fulfilled. */
    public function sendDelivered(Order $order): void
    {
        $email = $order->getCustomerEmail();
        if (null === $email || '' === $email) {
            return;
        }
        $this->emails->send('order_delivered', $this->tags($order), $email);
    }

    /** Customer "your order was refunded", sent on a full refund (admin- or Stripe-initiated). */
    public function sendRefunded(Order $order): void
    {
        $email = $order->getCustomerEmail();
        if (null === $email || '' === $email) {
            return;
        }
        $this->emails->send('order_refunded', $this->tags($order), $email);
    }

    /**
     * @return array<string, string>
     */
    private function tags(Order $order): array
    {
        $tax = $order->getTaxAmountMinor();
        $net = $order->getNetAmountMinor();

        return [
            'order_id' => (string) $order->getId(),
            'amount' => number_format($order->getAmountMinor() / 100, 2, ',', '.'),
            'currency' => strtoupper($order->getCurrency()),
            'form_name' => $order->getForm()?->getName() ?? '-',
            'variant' => $order->getVariantLabel() ?? '',
            'tax_amount' => null === $tax ? '' : number_format($tax / 100, 2, ',', '.'),
            'net_amount' => null === $net ? '' : number_format($net / 100, 2, ',', '.'),
            'tax_rate' => null === $order->getTaxRate() ? '' : rtrim(rtrim($order->getTaxRate(), '0'), '.'),
            'tax_name' => $order->getTaxName() ?? '',
        ];
    }
}
