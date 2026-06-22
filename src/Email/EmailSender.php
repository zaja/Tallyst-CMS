<?php

namespace App\Email;

use App\Repository\EmailTemplateRepository;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * The ONE send path for engine mails. Centralises the hard-won plumbing so it can't drift:
 *  - From is left UNSET → DefaultFromListener applies the configured identity (the 553 lesson);
 *  - sent via MailerInterface → SendEmailMessage → the async Messenger worker;
 *  - absolute URLs come from EmailRenderer (router context / default_uri).
 *
 * A disabled type is skipped — EXCEPT a non-canDisable type (e.g. password_reset), which always
 * sends regardless of any stale DB row, so the reset flow can never be broken from the admin.
 */
class EmailSender
{
    public function __construct(
        private readonly EmailRenderer $renderer,
        private readonly EmailTypeRegistry $registry,
        private readonly EmailTemplateRepository $templates,
        private readonly MailerInterface $mailer,
    ) {
    }

    /**
     * @param array<string, scalar|null> $tagValues
     * @param string|string[]            $to
     * @param string|null                $subjectOverride optional per-send subject (preserves an
     *                                                     existing per-form subject, e.g. notifySubject)
     */
    public function send(string $typeKey, array $tagValues, string|array $to, ?string $subjectOverride = null): void
    {
        $type = $this->registry->get($typeKey);
        if (null === $type) {
            throw new \InvalidArgumentException(\sprintf('Unknown email type "%s".', $typeKey));
        }

        if ($type->canDisable) {
            $override = $this->templates->findOneByIdentifier($typeKey);
            if (null !== $override && !$override->isEnabled()) {
                return; // admin disabled this mail
            }
        }

        $recipients = array_values(array_filter(
            array_map('strval', (array) $to),
            static fn (string $r): bool => '' !== trim($r),
        ));
        if ([] === $recipients) {
            return;
        }

        $rendered = $this->renderer->render($typeKey, $tagValues, $subjectOverride);

        $email = (new Email())
            ->to(...$recipients)
            ->subject($rendered->subject)
            ->html($rendered->html);
        if ('' !== $rendered->text) {
            $email->text($rendered->text);
        }

        // No ->from(): DefaultFromListener fills it. $mailer->send routes to the async worker.
        $this->mailer->send($email);
    }
}
