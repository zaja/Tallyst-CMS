<?php

namespace App\Tests\Ops;

use App\Messenger\ConsumableTransports;
use App\Messenger\WorkerHeartbeat;
use App\Ops\WorkerState;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Which of the three things an upgrade says about the worker.
 *
 * ⚠ WHY THREE AND NOT ONE. An owner handed the same setup block on every upgrade — for a worker that
 * has been running correctly for months — stops reading it, and then misses the upgrade where
 * something genuinely is wrong. Advice nobody needs is how you lose their attention for the time
 * they do need it.
 */
class WorkerStateTest extends KernelTestCase
{
    private function transports(): ConsumableTransports
    {
        self::bootKernel();

        return static::getContainer()->get(ConsumableTransports::class);
    }

    private function heartbeatWith(?array $transports): WorkerHeartbeat
    {
        $heartbeat = new WorkerHeartbeat(new ArrayAdapter());
        if (null !== $transports) {
            $heartbeat->beat($transports);
        }

        return $heartbeat;
    }

    /** Running and watching everything: one line, no block. */
    public function testAHealthyWorkerIsComplete(): void
    {
        $transports = $this->transports();
        $state = WorkerState::read($this->heartbeatWith($transports->expected()), $transports);

        self::assertTrue($state->isComplete());
        self::assertFalse($state->isIncomplete());
        self::assertFalse($state->isAbsent());
    }

    /**
     * ⚠ THE CASE THE WHOLE FEATURE IS FOR: a worker still running the pre-upgrade command. It looks
     * healthy — it runs, it sends mail — and the queue it is missing is named rather than left for
     * the owner to work out.
     */
    public function testAWorkerMissingAQueueIsIncompleteAndNamesIt(): void
    {
        $transports = $this->transports();
        $state = WorkerState::read($this->heartbeatWith(['async']), $transports);

        self::assertTrue($state->isIncomplete());
        self::assertSame(['scheduler_order_maintenance'], $state->missingQueues);
    }

    /** No heartbeat at all: the only case that earns the full block. */
    public function testNoWorkerIsAbsent(): void
    {
        $state = WorkerState::read($this->heartbeatWith(null), $this->transports());

        self::assertTrue($state->isAbsent());
        self::assertFalse($state->isComplete());
    }

    /**
     * ⚠ A worker that has not reported its queues is NOT accused of missing any. That happens for a
     * minute after any restart, and on every worker from before the queues were recorded — telling
     * those owners to fix something would be inventing a fault.
     */
    public function testAWorkerThatHasNotReportedItsQueuesIsTreatedAsComplete(): void
    {
        $state = WorkerState::read($this->heartbeatWith([]), $this->transports());

        self::assertTrue($state->isComplete(), 'silence is not evidence of a missing queue');
    }

    /**
     * ⚠ THE ORDERING INVARIANT, AND IT IS LOAD-BEARING. The heartbeat lives in cache.app and
     * `app:upgrade:finalize` runs `cache:clear` twice. If the state were read after that, every
     * upgrade would report "no worker" and print the full setup block — the exact repetition the
     * three branches exist to avoid, and it would look correct in every manual test on a machine
     * where the worker really is running.
     *
     * Asserted positionally on the source because that is where the invariant lives: no runtime
     * assertion can see that one line comes before another.
     */
    public function testTheWorkerStateIsReadBeforeTheCacheIsCleared(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2).'/src/Command/UpgradeFinalizeCommand.php');

        $read = strpos($source, 'WorkerState::read(');
        $firstClear = strpos($source, "['cache:clear']");

        self::assertNotFalse($read, 'the upgrade must read the worker state');
        self::assertNotFalse($firstClear, 'the upgrade must clear the cache');
        self::assertLessThan(
            $firstClear,
            $read,
            'the worker state must be read BEFORE cache:clear — the heartbeat lives in the cache it wipes',
        );
    }
}
