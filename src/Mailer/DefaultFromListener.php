<?php

namespace App\Mailer;

use App\Settings\SettingsManager;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Applies the Settings email identity (from_name / from_email / reply_to) to every outgoing
 * message that hasn't set its own. Runs on MessageEvent, which the transport dispatches
 * before sending — so it covers both synchronous and async mail (the SettingsMailerTransport
 * passes the global dispatcher to the SMTP transport it builds, so this still fires for
 * DB-SMTP sends).
 */
#[AsEventListener(event: MessageEvent::class)]
class DefaultFromListener
{
    public function __construct(private readonly SettingsManager $settings)
    {
    }

    public function __invoke(MessageEvent $event): void
    {
        $message = $event->getMessage();
        if (!$message instanceof Email) {
            return;
        }

        if ([] === $message->getFrom()) {
            $fromEmail = (string) $this->settings->get('mail_from_email');
            if ('' !== $fromEmail) {
                $message->from(new Address($fromEmail, (string) $this->settings->get('mail_from_name')));
            }
        }

        if ([] === $message->getReplyTo()) {
            $replyTo = (string) $this->settings->get('mail_reply_to');
            if ('' !== $replyTo) {
                $message->replyTo($replyTo);
            }
        }
    }
}
