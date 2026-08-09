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

        // The buyer's login link. Like the reset mail, this one cannot be switched off: it is the
        // ONLY way a customer can reach their purchases, so a disabled template would lock every
        // buyer out of the account area with no way back.
        yield new EmailType(
            key: 'customer_login',
            label: 'email.customer_login.label',
            tags: [
                'login_url' => 'email.customer_login.tag.login_url',
                'site_name' => 'email.customer_login.tag.site_name',
            ],
            requiredTags: ['login_url'],
            canDisable: false,
            defaultSubject: 'email.customer_login.subject',
            defaultBody: 'email.customer_login.body',
        );
    }
}
