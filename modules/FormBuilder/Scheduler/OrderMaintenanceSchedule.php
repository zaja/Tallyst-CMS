<?php

namespace Tallyst\FormBuilder\Scheduler;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Tallyst\FormBuilder\Message\SweepAbandonedOrdersMessage;

/**
 * Runs the unfinished-checkout deadline on the Messenger worker.
 *
 * ⚠ THIS HANGS OFF SOMETHING THE OWNER ALREADY HAS TO RUN, AND THAT IS THE WHOLE POINT. Every
 * install already needs `messenger:consume async` or no e-mail is ever delivered — it is required,
 * documented three ways in INSTALL.md, and reported by the readiness panel. Putting the sweep there
 * means there is no new step for an owner to skip.
 *
 * The alternative was a cron entry, and its failure mode is measured rather than imagined: this
 * project shipped `app:member:prune` that way, and an owner who never adds the entry gets no error,
 * no warning and no cleanup — silently, for ever. Adding a second such step for something that
 * decides whether a customer is told their purchase failed was not worth it.
 *
 * ⚠ The worker can still be stopped, so this is not a guarantee on its own. The readiness panel
 * reports when the sweep last ran, which is what turns "nothing to do" and "nothing is running" into
 * two different answers.
 *
 * Hourly, not daily: the deadline is 24 hours, so an hourly pass closes a checkout within an hour of
 * it expiring instead of up to a day later, at the cost of one trivial query per hour.
 */
#[AsSchedule('order_maintenance')]
final readonly class OrderMaintenanceSchedule implements ScheduleProviderInterface
{
    public function __construct(private CacheInterface $cache)
    {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::every('1 hour', new SweepAbandonedOrdersMessage()))
            // Stateful so a worker restart does not re-run everything it missed in a burst; the
            // sweep is idempotent anyway, but a restart storm helps nobody.
            ->stateful($this->cache);
    }
}
