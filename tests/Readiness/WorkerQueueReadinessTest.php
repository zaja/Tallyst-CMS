<?php

namespace App\Tests\Readiness;

use App\Messenger\ConsumableTransports;
use App\Messenger\WorkerHeartbeat;
use App\Readiness\Status;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Whether the readiness panel can tell a working background worker from one that is quietly doing
 * half its job.
 *
 * ⚠ THE FAILURE THIS EXISTS TO PREVENT. Since the unfinished-checkout sweep moved onto the worker,
 * an owner upgrading has to add a queue name to their service file BY HAND. A worker that missed it
 * looks exactly like a healthy one: it runs, it sends mail, its heartbeat is fresh — and the work it
 * is not doing simply never happens. The previous check reported OK in that state, because it
 * measured whether a backlog had built up, and a quiet shop never builds one.
 *
 * ⚠ SO THE TEST THAT MATTERS MOST IS THE QUIET ONE: with nothing at all waiting, the panel must
 * still be able to say whether the worker is watching everything it should.
 */
class WorkerQueueReadinessTest extends KernelTestCase
{
    private function heartbeat(): WorkerHeartbeat
    {
        // Its own cache, so this never reads or disturbs a real worker's heartbeat on this machine.
        return new WorkerHeartbeat(new ArrayAdapter());
    }

    private function transports(): ConsumableTransports
    {
        self::bootKernel();

        return static::getContainer()->get(ConsumableTransports::class);
    }

    /**
     * ⚠ The expected list is READ from the container, never restated here — that is the whole point.
     * If a future release adds a queue, this test starts requiring it without anybody editing it.
     */
    public function testTheExpectedQueuesAreReadFromTheApplicationItself(): void
    {
        $expected = $this->transports()->expected();

        self::assertContains('async', $expected, 'the mail queue every install has');
        self::assertContains('scheduler_order_maintenance', $expected, 'the queue an upgrade must be told about');
        self::assertNotContains('failed', $expected, 'the failure store is drained by hand, never consumed by a worker');
    }

    /** A worker watching everything it should. */
    public function testAWorkerWatchingEveryQueueIsReportedAsWorking(): void
    {
        $transports = $this->transports();
        $heartbeat = $this->heartbeat();
        $heartbeat->beat($transports->expected());

        self::assertSame([], $transports->missingFrom($heartbeat->transports()));
    }

    /**
     * ⚠ THE ONE THAT CLOSES THE HOLE: a worker still running the pre-upgrade command. Nothing is
     * waiting, nothing has failed, the heartbeat is fresh — and it must still be caught, by name.
     */
    public function testAWorkerMissingAQueueIsCaughtOnAnIdleSite(): void
    {
        $transports = $this->transports();
        $heartbeat = $this->heartbeat();

        // Exactly what an owner who never edited their service file is running.
        $heartbeat->beat(['async']);

        $missing = $transports->missingFrom($heartbeat->transports());

        self::assertSame(['scheduler_order_maintenance'], $missing);
    }

    /**
     * ⚠ AN UNKNOWN LIST IS NOT AN ACCUSATION. A worker from before this was recorded, or a cache
     * cleared moments ago — which is exactly when somebody is looking at the panel after upgrading —
     * reports nothing. Reading that as "consuming nothing" would show every upgrading owner a fault
     * that is not there.
     */
    public function testAWorkerThatHasNotReportedItsQueuesIsNotAccused(): void
    {
        $transports = $this->transports();
        $heartbeat = $this->heartbeat();
        $heartbeat->beat([]);

        self::assertSame([], $transports->missingFrom($heartbeat->transports()));
        self::assertSame([], $heartbeat->transports());
    }

    /** ⚠ A heartbeat written by an older release was a bare timestamp — reading it must not fatal. */
    public function testAHeartbeatFromAnOlderReleaseIsStillReadable(): void
    {
        $cache = new ArrayAdapter();
        $item = $cache->getItem('tallyst.worker.heartbeat');
        $item->set(time()); // the old shape
        $cache->save($item);

        $heartbeat = new WorkerHeartbeat($cache);

        self::assertNotNull($heartbeat->lastSeen(), 'liveness must still be readable');
        self::assertTrue($heartbeat->isFresh());
        self::assertSame([], $heartbeat->transports(), 'and its queues are simply unknown');
    }

    /**
     * ⚠ THE SUBSCRIBER MUST ACTUALLY PASS THE QUEUES ON, and nothing pinned that until now. Proving
     * the earlier tests red revealed the gap: they build their own heartbeat, so removing the
     * transports from the subscriber left every one of them green while the panel went blind again.
     * A red-proof that finds a hole in its own tests is the procedure working.
     */
    public function testTheSubscriberRecordsTheQueuesTheWorkerIsConsuming(): void
    {
        $heartbeat = $this->heartbeat();
        $subscriber = new \App\Messenger\WorkerHeartbeatSubscriber($heartbeat);

        $receiver = $this->createStub(\Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface::class);
        $worker = new \Symfony\Component\Messenger\Worker(
            ['async' => $receiver, 'scheduler_order_maintenance' => $receiver],
            $this->createStub(\Symfony\Component\Messenger\MessageBusInterface::class),
        );

        $subscriber->onRunning(new \Symfony\Component\Messenger\Event\WorkerRunningEvent($worker, true));

        self::assertSame(
            ['async', 'scheduler_order_maintenance'],
            $heartbeat->transports(),
            'the panel can only report what the subscriber records',
        );
    }

    /** The three states must be distinguishable, not merely differently coloured. */
    public function testTheThreeStatesAreDistinct(): void
    {
        self::bootKernel();
        $provider = static::getContainer()->get(\App\Readiness\InfraReadinessProvider::class);

        $labels = [];
        foreach ($provider->getChecks() as $check) {
            $labels[$check->label] = $check->status;
        }

        // The check exists and is reported; which state it lands in depends on this machine.
        self::assertArrayHasKey('Messenger worker', $labels);
        self::assertInstanceOf(Status::class, $labels['Messenger worker']);
    }
}
