<?php

namespace Tallyst\FormBuilder\Readiness;

use App\Readiness\Check;
use App\Readiness\ReadinessCheckProviderInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tallyst\FormBuilder\Repository\OrderRepository;
use Tallyst\FormBuilder\Service\AbandonedOrderSweeper;

/**
 * Tells the owner whether unfinished checkouts are actually being closed.
 *
 * ⚠ THIS IS THE ONLY THING THAT MAKES THE DEADLINE TRUSTWORTHY. The sweep runs on the Messenger
 * worker, and a worker can be stopped, crash after a deploy, or — the case that will actually
 * happen — be running an OLD command line that does not name the scheduler queue at all. In every
 * one of those the failure is silent: e-mail still goes out, orders still take payments, and the
 * only symptom is checkouts quietly piling up in "awaiting payment" exactly as they did before this
 * feature existed.
 *
 * So the check does not ask "is the scheduler configured" — it asks the question the owner cares
 * about: are there checkouts past the deadline that nothing has closed?
 *
 * ⚠ It reports what it MEASURED. A backlog is a WARNING, never a PROBLEM: a shop that has just been
 * upgraded legitimately has one for a few minutes, and the panel must not cry wolf on a site that is
 * about to fix itself.
 */
final readonly class AbandonedOrderReadinessProvider implements ReadinessCheckProviderInterface
{
    private const string GROUP = 'admin.readiness.group.worker';

    /**
     * Generous on purpose: the sweep runs hourly, so anything under this is ordinary scheduling
     * jitter or a worker that was briefly restarted, not a fault worth showing.
     */
    private const string STALE_AFTER = '-6 hours';

    public function __construct(
        private OrderRepository $orders,
        private AbandonedOrderSweeper $sweeper,
        private TranslatorInterface $translator,
    ) {
    }

    /** @param array<string, string|int> $params */
    private function t(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $params, 'admin');
    }

    public function getChecks(): iterable
    {
        $now = new \DateTimeImmutable();
        $group = $this->t(self::GROUP);
        $label = $this->t('admin.readiness.order_sweep.label');

        $waiting = $this->orders->countAbandonedSince($now->modify(AbandonedOrderSweeper::DEADLINE));
        $lastRun = $this->sweeper->lastRunAt();

        // Never run at all, with a backlog to prove it matters: almost always a worker whose command
        // line predates the scheduler queue.
        if (null === $lastRun) {
            yield $waiting > 0
                ? Check::warning($group, $label,
                    $this->t('admin.readiness.order_sweep.detail.never', ['%count%' => $waiting]),
                    $this->t('admin.readiness.order_sweep.fix'))
                : Check::ok($group, $label, $this->t('admin.readiness.order_sweep.detail.nothing_yet'));

            return;
        }

        if ($lastRun < $now->modify(self::STALE_AFTER)) {
            yield Check::warning($group, $label,
                $this->t('admin.readiness.order_sweep.detail.stale', ['%when%' => $lastRun->format('Y-m-d H:i')]),
                $this->t('admin.readiness.order_sweep.fix'));

            return;
        }

        yield Check::ok($group, $label,
            $this->t('admin.readiness.order_sweep.detail.ok', ['%when%' => $lastRun->format('Y-m-d H:i')]));
    }
}
