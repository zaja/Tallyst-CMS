<?php

namespace Tallyst\FormBuilder\Service;

use App\Email\EmailSender;
use App\Settings\SettingsManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;
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
        private readonly TranslatorInterface $translator,
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

    /**
     * Admin notice of a new paid order → the order_admin_email setting, else the env fallback. For MANUAL
     * fulfilment the admin needs everything to ship by (hard point #6): the delivery method + amount + the
     * FORMATTED delivery address go in `delivery_details` (a <pre> block, empty for a digital/MoR order),
     * and the buyer's OTHER submitted fields go in `form_data` (the address excluded — it's shown above).
     */
    public function sendAdminNotice(Order $order): void
    {
        $to = ((string) $this->settings->get('order_admin_email')) ?: $this->adminEmail;
        $this->emails->send('order_admin', $this->tags($order) + [
            'customer_email' => $order->getCustomerEmail() ?? '-',
            'form_data' => $order->getFormDataSummary(),
            // Kept advertised for custom templates (the full dump incl. the address on one line).
            'submission_summary' => $order->getSubmissionSummary(),
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
            // Chosen delivery as one locale-neutral line (label — amount CUR), or an em-dash when none —
            // so the default mail body reads cleanly for both shipped and digital/MoR orders.
            'shipping' => $this->shippingLine($order),
            // A composed, translated multi-line block (product/delivery/tax + the formatted address) for a
            // SHIPPED order, or EMPTY for a digital/MoR order — so those mails are unchanged (bar an empty
            // <pre>). Rendered in a <pre> block by the default bodies.
            'delivery_details' => $this->deliveryDetails($order),
            // MoR (Dodo, Faza 8): the licence key + provider invoice link. Raw tags for custom templates;
            // `mor_delivery` is a composed, gracefully-empty block for the default body (like delivery_details)
            // — EMPTY for a non-MoR / no-licence order, so the default body shows no dangling "Licence:" line.
            'license_key' => $order->getLicenseKey() ?? '',
            'invoice_url' => $order->getInvoiceUrl() ?? '',
            'mor_delivery' => $this->morDelivery($order),
        ];
    }

    /**
     * The MoR (Dodo) licence + invoice as a translated, multi-line PLAIN-TEXT block (rendered in a <pre> by
     * the default bodies). Empty when the order has neither — so a non-MoR / no-licence order's mail is
     * unchanged (bar an empty <pre>), and there is never a dangling label. Same locale handling as
     * deliveryDetails (the worker has no request locale). Faza 8.
     */
    private function morDelivery(Order $order): string
    {
        $license = $order->getLicenseKey();
        $invoice = $order->getInvoiceUrl();
        $hasLicense = null !== $license && '' !== $license;
        $hasInvoice = null !== $invoice && '' !== $invoice;
        if (!$hasLicense && !$hasInvoice) {
            return '';
        }

        $locale = (string) ($this->settings->get('app_locale') ?: 'en');
        $lines = [];
        if ($hasLicense) {
            $lines[] = $this->translator->trans('email.parts.license_line', ['%license_key%' => $license], 'emails', $locale);
        }
        if ($hasInvoice) {
            $lines[] = $this->translator->trans('email.parts.invoice_line', ['%invoice_url%' => $invoice], 'emails', $locale);
        }

        return implode("\n", $lines);
    }

    /**
     * The delivery breakdown + formatted address as a translated, multi-line block. Empty when the order
     * has no delivery (digital / MoR) so the mail stays unchanged. The labels come from the `emails`
     * domain translated with app_locale (the worker has no request locale — same as EmailRenderer).
     */
    private function deliveryDetails(Order $order): string
    {
        if (null === $order->getShippingLabel()) {
            return '';
        }

        $locale = (string) ($this->settings->get('app_locale') ?: 'en');
        $currency = strtoupper($order->getCurrency());
        $money = static fn (int $minor): string => number_format($minor / 100, 2, ',', '.').' '.$currency;

        $shipMinor = (int) $order->getShippingAmountMinor();
        $productMinor = max(0, $order->getAmountMinor() - $shipMinor);

        $taxLine = '';
        if (null !== $order->getTaxAmountMinor()) {
            $taxLine = $this->translator->trans('email.parts.tax_incl', [
                '%tax_name%' => $order->getTaxName() ?? '',
                '%tax_rate%' => null === $order->getTaxRate() ? '' : rtrim(rtrim($order->getTaxRate(), '0'), '.'),
                '%tax_amount%' => $money((int) $order->getTaxAmountMinor()),
            ], 'emails', $locale);
        }

        return $this->translator->trans('email.parts.delivery_block', [
            '%product%' => $money($productMinor),
            '%method%' => $order->getShippingLabel(),
            '%shipping%' => $money($shipMinor),
            '%tax_line%' => $taxLine,
            '%address%' => $order->getShippingAddressFormatted(),
        ], 'emails', $locale);
    }

    /** "Express — 12,00 EUR", or just the label when there's no amount, or "—" when no delivery. */
    private function shippingLine(Order $order): string
    {
        $label = $order->getShippingLabel();
        if (null === $label) {
            return '—';
        }

        $amount = $order->getShippingAmountMinor();

        return null === $amount
            ? $label
            : $label.' — '.number_format($amount / 100, 2, ',', '.').' '.strtoupper($order->getCurrency());
    }
}
