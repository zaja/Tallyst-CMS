<?php

namespace App\Email;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Declares email types. IoC like the settings-section / shortcode registries: Core ships the
 * system mails (password reset) and each MODULE declares its own (e.g. FormBuilder: order +
 * form-notification mails), so Core never has to know about Order/FormSubmission.
 */
#[AutoconfigureTag('app.email_type')]
interface EmailTypeProviderInterface
{
    /**
     * @return iterable<EmailType>
     */
    public function getEmailTypes(): iterable;
}
