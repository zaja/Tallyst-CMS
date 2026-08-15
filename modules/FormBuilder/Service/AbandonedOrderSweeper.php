<?php

namespace Tallyst\FormBuilder\Service;

use App\Email\EmailSender;
use App\Settings\SettingsManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\WorkflowInterface;
use Tallyst\FormBuilder\Repository\OrderRepository;

/**
 * Closes checkouts that were never finished — the floor under everything the providers do or don't
 * tell us.
 *
 * ⚠ WHY A DEADLINE AND NOT JUST PROVIDER EVENTS. Most abandoned checkouts produce no event at all:
 * somebody closes the window and tells nobody. Stripe reports nothing when a card is declined inside
 * Checkout, because the buyer is still standing there able to try another. And the provider events
 * that DO exist only arrive once the site owner has subscribed them in their dashboard, which an
 * upgrade cannot do for them. So this is the part that works everywhere, with no configuration:
 * after the deadline, a checkout nobody completed is closed.
 *
 * ⚠ 24 HOURS IS NOT A GUESS AT IMPATIENCE. It is long enough to cover a slow method — a bank
 * transfer or SEPA debit legitimately takes most of a day — so the deadline never closes something
 * that was still on its way. And if one settles later anyway, the money still wins: the verified
 * webhook may reopen the order, because status follows what the PROVIDER asserts, never what the
 * clock says.
 */
class AbandonedOrderSweeper
{
    /** Long enough to outlast a slow bank transfer; short enough to be an answer, not a limbo. */
    public const string DEADLINE = '-24 hours';

    /**
     * The moment this site started watching for unfinished checkouts, stamped by the migration that
     * introduced the feature.
     *
     * ⚠ IT IS NOT A CONVENIENCE — IT IS WHAT STOPS AN UPGRADE MAILING HISTORIC CUSTOMERS. Without it,
     * the first run after an upgrade would write to everyone who ever abandoned a basket, including
     * people who walked away months before the shop could notice. A time window ("younger than 48
     * hours") does NOT prevent that, because an upgrade can land at any point relative to those
     * orders; only knowing when the watching began does.
     */
    public const string ACTIVATED_SETTING = 'order_failure_tracking_since';

    /** Set after every run, so the readiness panel can tell "nothing to do" from "nothing is running". */
    public const string LAST_RUN_SETTING = 'order_sweep_last_run';

    public function __construct(
        private readonly OrderRepository $orders,
        #[Target('orderStateMachine')]
        private readonly WorkflowInterface $orderStateMachine,
        private readonly EntityManagerInterface $em,
        private readonly SettingsManager $settings,
        private readonly LoggerInterface $logger,
        private readonly EmailSender $emails,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return array{closed: int, notifiable: int} `notifiable` counts the ones a buyer may be written
     *                                             to about — see the activation rule above
     */
    public function sweep(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $cutoff = $now->modify(self::DEADLINE);
        $activatedAt = $this->activatedAt();

        $closed = 0;
        $notifiable = 0;
        /** @var \Tallyst\FormBuilder\Entity\Order[] $toNotify */
        $toNotify = [];

        foreach ($this->orders->findAbandonedSince($cutoff) as $order) {
            if (!$this->orderStateMachine->can($order, 'fail')) {
                continue; // resolved between the query and here — the provider's word wins
            }

            $this->orderStateMachine->apply($order, 'fail');
            ++$closed;

            if ($this->mayNotify($order->getCreatedAt(), $activatedAt)) {
                $toNotify[] = $order;
                ++$notifiable;
            }
        }

        $this->settings->set(self::LAST_RUN_SETTING, $now->format(\DateTimeInterface::ATOM));
        $this->em->flush();

        // ⚠ AFTER the flush, never before. If the write failed, nothing was closed, and telling
        // somebody their purchase did not go through while the shop still thinks it is pending would
        // be the one message impossible to take back.
        foreach ($toNotify as $order) {
            $this->notify($order);
        }

        if ($closed > 0) {
            $this->logger->info('Closed {closed} unfinished checkout(s); {notifiable} eligible to be told.', [
                'closed' => $closed,
                'notifiable' => $notifiable,
            ]);
        }

        return ['closed' => $closed, 'notifiable' => $notifiable];
    }

    /**
     * Tells the buyer their purchase was never completed, and offers them the way back.
     *
     * ⚠ Every failure here is swallowed. A mail problem must not stop the sweep from closing the
     * remaining checkouts, and it must certainly not make the whole run fail and retry — which would
     * mail everybody it had already told.
     */
    private function notify(\Tallyst\FormBuilder\Entity\Order $order): void
    {
        $email = (string) $order->getCustomerEmail();
        if ('' === $email) {
            return; // no address was ever captured — there is nobody to write to
        }

        try {
            $this->emails->send('order_failed', [
                'order_id' => (string) $order->getId(),
                'product' => $order->getProductLabel(),
                'retry_url' => $this->retryUrl($order),
                'site_name' => (string) $this->settings->get('site_name'),
            ], $email);
        } catch (\Throwable $e) {
            $this->logger->error('Could not tell the buyer their checkout was not completed.', [
                'order' => $order->getId(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * ⚠ Absolute, because this is read in an inbox — a relative link there is simply dead. The
     * router context comes from `default_uri` in the worker, which is why DEFAULT_URI must be the
     * real public URL.
     */
    private function retryUrl(\Tallyst\FormBuilder\Entity\Order $order): string
    {
        return $this->urls->generate('form_builder_order_retry', [
            'id' => $order->getId(),
            't' => $order->getThankYouToken(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * ⚠ THE SILENCE RULE. A checkout abandoned before this site started watching is closed WITHOUT a
     * word, whatever its age. The owner upgrading Tallyst must not become the owner who mailed every
     * customer who ever changed their mind.
     */
    public function mayNotify(?\DateTimeImmutable $createdAt, ?\DateTimeImmutable $activatedAt): bool
    {
        if (null === $createdAt) {
            return false;
        }

        // No stamp at all means we cannot prove the order came after the feature — so we stay quiet.
        // Erring towards silence is the only safe direction: an unsent message costs a little, and a
        // wrongly sent one costs the owner's standing with their customers.
        if (null === $activatedAt) {
            return false;
        }

        return $createdAt > $activatedAt;
    }

    public function activatedAt(): ?\DateTimeImmutable
    {
        $raw = $this->settings->get(self::ACTIVATED_SETTING);
        if (!is_string($raw) || '' === $raw) {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }

    public function lastRunAt(): ?\DateTimeImmutable
    {
        $raw = $this->settings->get(self::LAST_RUN_SETTING);
        if (!is_string($raw) || '' === $raw) {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }
}
