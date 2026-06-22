<?php

namespace App\Email;

/**
 * Core (system) email types. Today: password reset. System mail like 2FA has no e-mail (TOTP).
 */
class CoreEmailTypeProvider implements EmailTypeProviderInterface
{
    public function getEmailTypes(): iterable
    {
        yield new EmailType(
            key: 'password_reset',
            label: 'Promjena lozinke',
            tags: [
                'reset_url' => 'Poveznica za postavljanje nove lozinke (obavezno).',
                'site_name' => 'Naziv sajta.',
            ],
            requiredTags: ['reset_url'],
            canDisable: false,
            defaultSubject: 'Promjena lozinke — {site_name}',
            defaultBody: <<<HTML
                <p>Bok,</p>
                <p>Zatražena je promjena lozinke za tvoj račun na {site_name}. Za postavljanje nove lozinke otvori poveznicu:</p>
                <p><a href="{reset_url}">{reset_url}</a></p>
                <p>Ako nisi ti zatražio/la promjenu, slobodno zanemari ovaj e-mail.</p>
                <p>{site_name}</p>
                HTML,
        );
    }
}
