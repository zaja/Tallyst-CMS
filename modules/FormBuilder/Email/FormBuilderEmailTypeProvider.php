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
        yield new EmailType(
            key: 'order_confirmation',
            label: 'Potvrda narudžbe (kupcu)',
            tags: [
                'order_id' => 'Broj narudžbe.',
                'amount' => 'Iznos (formatiran).',
                'currency' => 'Valuta.',
                'form_name' => 'Naziv forme/proizvoda.',
                'site_name' => 'Naziv sajta.',
            ],
            requiredTags: [],
            canDisable: true,
            defaultSubject: 'Potvrda narudžbe #{order_id}',
            defaultBody: <<<HTML
                <p>Hvala na narudžbi!</p>
                <p><strong>Narudžba #{order_id}</strong><br>
                Proizvod: {form_name}<br>
                Iznos: {amount} {currency}<br>
                Status: plaćeno</p>
                <p>{site_name}</p>
                HTML,
        );

        yield new EmailType(
            key: 'order_admin',
            label: 'Nova narudžba (administratoru)',
            tags: [
                'order_id' => 'Broj narudžbe.',
                'amount' => 'Iznos (formatiran).',
                'currency' => 'Valuta.',
                'form_name' => 'Naziv forme/proizvoda.',
                'customer_email' => 'E-mail kupca.',
                'site_name' => 'Naziv sajta.',
            ],
            requiredTags: [],
            canDisable: true,
            defaultSubject: 'Nova plaćena narudžba #{order_id}',
            defaultBody: <<<HTML
                <p>Nova plaćena narudžba.</p>
                <p><strong>Narudžba #{order_id}</strong><br>
                Forma: {form_name}<br>
                Iznos: {amount} {currency}<br>
                Kupac: {customer_email}</p>
                HTML,
        );

        yield new EmailType(
            key: 'order_delivered',
            label: 'Narudžba isporučena (kupcu)',
            tags: [
                'order_id' => 'Broj narudžbe.',
                'form_name' => 'Naziv forme/proizvoda.',
                'site_name' => 'Naziv sajta.',
            ],
            requiredTags: [],
            canDisable: true,
            defaultSubject: 'Vaša narudžba #{order_id} je isporučena',
            defaultBody: <<<HTML
                <p>Dobre vijesti — vaša narudžba je isporučena.</p>
                <p><strong>Narudžba #{order_id}</strong><br>
                Proizvod: {form_name}</p>
                <p>Hvala na povjerenju!</p>
                <p>{site_name}</p>
                HTML,
        );

        yield new EmailType(
            key: 'order_refunded',
            label: 'Narudžba refundirana (kupcu)',
            tags: [
                'order_id' => 'Broj narudžbe.',
                'amount' => 'Iznos (formatiran).',
                'form_name' => 'Naziv forme/proizvoda.',
                'site_name' => 'Naziv sajta.',
            ],
            requiredTags: [],
            canDisable: true,
            defaultSubject: 'Povrat za narudžbu #{order_id}',
            defaultBody: <<<HTML
                <p>Vaša narudžba je refundirana.</p>
                <p><strong>Narudžba #{order_id}</strong><br>
                Proizvod: {form_name}<br>
                Vraćeni iznos: {amount}</p>
                <p>Sredstva će se vratiti na vaš način plaćanja u uobičajenom roku.</p>
                <p>{site_name}</p>
                HTML,
        );

        yield new EmailType(
            key: 'form_notification',
            label: 'Obavijest o prijavi forme',
            tags: [
                'form_name' => 'Naziv forme.',
                'submission_summary' => 'Sažetak prijave (polje: vrijednost).',
                'site_name' => 'Naziv sajta.',
            ],
            requiredTags: [],
            canDisable: true,
            defaultSubject: 'Nova prijava: {form_name}',
            defaultBody: <<<HTML
                <p>Nova prijava forme „{form_name}".</p>
                <pre>{submission_summary}</pre>
                <p>{site_name}</p>
                HTML,
        );
    }
}
