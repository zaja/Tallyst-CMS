<?php

namespace App\Messenger;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

/**
 * Writes a throttled "last seen" heartbeat while a messenger worker runs. WorkerRunningEvent fires
 * on every worker loop (even when idle), so we throttle writes to avoid hammering the cache. The
 * readiness panel reads WorkerHeartbeat to report liveness honestly (fresh => active; stale/missing
 * => "provjeri ručno"). Auto-registered via autoconfigure.
 */
class WorkerHeartbeatSubscriber implements EventSubscriberInterface
{
    private const THROTTLE = 15; // minimum seconds between writes

    private int $lastWrite = 0;

    public function __construct(
        private readonly WorkerHeartbeat $heartbeat,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [WorkerRunningEvent::class => 'onRunning'];
    }

    public function onRunning(WorkerRunningEvent $event): void
    {
        $now = time();
        if ($now - $this->lastWrite < self::THROTTLE) {
            return;
        }
        $this->lastWrite = $now;
        $this->heartbeat->beat();
    }
}
