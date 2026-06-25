<?php

namespace App\Email;

/**
 * Core (system) email types. Today: password reset. System mail like 2FA has no e-mail (TOTP).
 */
class CoreEmailTypeProvider implements EmailTypeProviderInterface
{
    public function getEmailTypes(): iterable
    {
        // Label/tag-descriptions/defaultSubject/defaultBody are `emails`-domain keys (translated at the
        // render site: labels/tags in the admin, subject/body in EmailRenderer with the app_locale).
        yield new EmailType(
            key: 'password_reset',
            label: 'email.password_reset.label',
            tags: [
                'reset_url' => 'email.password_reset.tag.reset_url',
                'site_name' => 'email.password_reset.tag.site_name',
            ],
            requiredTags: ['reset_url'],
            canDisable: false,
            defaultSubject: 'email.password_reset.subject',
            defaultBody: 'email.password_reset.body',
        );
    }
}
