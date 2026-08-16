<?php

namespace App\Messenger;

use Psr\Cache\CacheItemPoolInterface;

/**
 * A "last seen" heartbeat for the messenger worker, stored in cache.app. The single source of the
 * cache key + freshness window — written (throttled) by WorkerHeartbeatSubscriber while a worker
 * runs, read by the readiness panel. A fresh heartbeat means a worker is genuinely running; a
 * stale/missing one is reported as "check manually" (never a hard "dead" claim — a worker that has
 * just restarted has not beaten yet).
 *
 * ⚠ IT ALSO RECORDS WHICH QUEUES THAT WORKER IS CONSUMING, and that is not a detail. Since the
 * unfinished-checkout sweep moved onto the worker, an owner upgrading has to add a queue name to
 * their service file by hand. Whether they did is otherwise invisible: a worker consuming half of
 * what it should looks exactly like a healthy one, and the work it is not doing simply never
 * happens. The worker knows its own transports (Worker::getMetadata()->getTransportNames()), so the
 * panel can state the fact instead of inferring it from whether a backlog has built up — which only
 * ever answers on a site that already has a problem.
 */
class WorkerHeartbeat
{
    private const KEY = 'tallyst.worker.heartbeat';
    private const TTL = 300;          // cache item lifetime (seconds)
    private const FRESH_WINDOW = 120; // "alive" if seen within this many seconds

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @param list<string> $transportNames the queues this worker consumes, as it reports them
     */
    public function beat(array $transportNames = []): void
    {
        $item = $this->cache->getItem(self::KEY);
        $item->set(['at' => time(), 'transports' => array_values($transportNames)]);
        $item->expiresAfter(self::TTL);
        $this->cache->save($item);
    }

    public function lastSeen(): ?int
    {
        $value = $this->read();

        return \is_int($value['at'] ?? null) ? $value['at'] : null;
    }

    /**
     * The queues the last-seen worker was consuming.
     *
     * ⚠ An EMPTY list means "not known yet", never "consuming nothing". A worker beating from an
     * older release records no list at all, and the whole record is wiped by `cache:clear` — which
     * happens on every upgrade, i.e. exactly when somebody is most likely to be looking at this.
     * Callers must treat empty as an open question, not as a failure.
     *
     * @return list<string>
     */
    public function transports(): array
    {
        $value = $this->read();
        $names = $value['transports'] ?? [];

        return \is_array($names) ? array_values(array_filter($names, 'is_string')) : [];
    }

    public function isFresh(): bool
    {
        $last = $this->lastSeen();

        return null !== $last && (time() - $last) <= self::FRESH_WINDOW;
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        $item = $this->cache->getItem(self::KEY);
        if (!$item->isHit()) {
            return [];
        }

        $value = $item->get();

        // ⚠ A heartbeat written before this record grew was a bare timestamp. Reading one must not
        // fatal on the first request after an upgrade, while the old item is still in the cache.
        if (\is_int($value)) {
            return ['at' => $value, 'transports' => []];
        }

        return \is_array($value) ? $value : [];
    }
}
