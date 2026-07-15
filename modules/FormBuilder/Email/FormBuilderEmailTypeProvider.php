<?php

namespace Tallyst\FormBuilder\Email;

use App\Email\EmailType;
use App\Email\EmailTypeProviderInterface;

/**
 * FormBuilder's email types — declared by the module (Core stays ignorant of Order /
 * FormSubmission). The send sites build the {tag} VALUES from their own context; this only
 * declares the inventory + defaults.
 */
class FormBuilderEmailTypeProvider implements EmailTypeProviderInterface
{
    public function getEmailTypes(): iterable
    {
        // Label/tag-descriptions/defaultSubject/defaultBody are `emails`-domain keys (the actual default
        // text lives in modules/FormBuilder/translations/emails.<locale>.yaml). Tag VALUES are unchanged.
        yield new EmailType(
            key: 'order_confirmation',
            label: 'email.order_confirmation.label',
            tags: [
                'order_id' => 'email.order_confirmation.tag.order_id',
                'amount' => 'email.order_confirmation.tag.amount',
                'currency' => 'email.order_confirmation.tag.currency',
                'tax_amount' => 'email.order_confirmation.tag.tax_amount',
                'net_amount' => 'email.order_confirmation.tag.net_amount',
                'tax_rate' => 'email.order_confirmation.tag.tax_rate',
                'tax_name' => 'email.order_confirmation.tag.tax_name',
                'form_name' => 'email.order_confirmation.tag.form_name',
                'variant' => 'email.order_confirmation.tag.variant',
                'shipping' => 'email.order_confirmation.tag.shipping',
                'delivery_details' => 'email.order_confirmation.tag.delivery_details',
                // MoR (Dodo): the licence + a link to the provider invoice (Faza 8). Raw tags for custom
                // templates; `mor_delivery` is a composed, gracefully-empty block for the default body
                // (like delivery_details) — empty for a non-MoR / no-licence order, so no dangling label.
                'license_key' => 'email.order_confirmation.tag.license_key',
                'invoice_url' => 'email.order_confirmation.tag.invoice_url',
                'mor_delivery' => 'email.order_confirmation.tag.mor_delivery',
                'site_name' => 'email.order_confirmation.tag.site_name',
            ],
            requiredTags: [],
            canDisable: true,
            defaultSubject: 'email.order_confirmation.subject',
            defaultBody: 'email.order_confirmation.body',
        );

        yield new EmailType(
            key: 'order_admin',
            label: 'email.order_admin.label',
            tags: [
                'order_id' => 'email.order_admin.tag.order_id',
                'amount' => 'email.order_admin.tag.amount',
                'currency' => 'email.order_admin.tag.currency',
                'tax_amount' => 'email.order_admin.tag.tax_amount',
                'net_amount' => 'email.order_admin.tag.net_amount',
                'tax_rate' => 'email.order_admin.tag.tax_rate',
                'tax_name' => 'email.order_admin.tag.tax_name',
                'form_name' => 'email.order_admin.tag.form_name',
                'variant' => 'email.order_admin.tag.variant',
                'shipping' => 'email.order_admin.tag.shipping',
                'delivery_details' => 'email.order_admin.tag.delivery_details',
                // MoR (Dodo): licence + provider invoice for the admin too (Faza 8).
                'license_key' => 'email.order_admin.tag.license_key',
                'invoice_url' => 'email.order_admin.tag.invoice_url',
                'mor_delivery' => 'email.order_admin.tag.mor_delivery',
                'customer_email' => 'email.order_admin.tag.customer_email',
                'form_data' => 'email.order_admin.tag.form_data',
                'submission_summary' => 'email.order_admin.tag.submission_summary',
                'site_name' => 'email.order_admin.tag.site_name',
            ],
            requiredTags: [],
            canDisable: true,
            defaultSubject: 'email.order_admin.subject',
            defaultBody: 'email.order_admin.body',
        );

        yield new EmailType(
            key: 'order_delivered',
            label: 'email.order_delivered.label',
            tags: [
                'order_id' => 'email.order_delivered.tag.order_id',
                'form_name' => 'email.order_delivered.tag.form_name',
                'variant' => 'email.order_delivered.tag.variant',
                'site_name' => 'email.order_delivered.tag.site_name',
            ],
            requiredTags: [],
            canDisable: true,
            defaultSubject: 'email.order_delivered.subject',
            defaultBody: 'email.order_delivered.body',
        );

        yield new EmailType(
            key: 'order_refunded',
            label: 'email.order_refunded.label',
            tags: [
                'order_id' => 'email.order_refunded.tag.order_id',
                'amount' => 'email.order_refunded.tag.amount',
                'form_name' => 'email.order_refunded.tag.form_name',
                'variant' => 'email.order_refunded.tag.variant',
                'site_name' => 'email.order_refunded.tag.site_name',
            ],
            requiredTags: [],
            canDisable: true,
            defaultSubject: 'email.order_refunded.subject',
            defaultBody: 'email.order_refunded.body',
        );

        yield new EmailType(
            key: 'form_notification',
            label: 'email.form_notification.label',
            tags: [
                'form_name' => 'email.form_notification.tag.form_name',
                'submission_summary' => 'email.form_notification.tag.submission_summary',
                'site_name' => 'email.form_notification.tag.site_name',
            ],
            requiredTags: [],
            canDisable: true,
            defaultSubject: 'email.form_notification.subject',
            defaultBody: 'email.form_notification.body',
        );
    }
}
