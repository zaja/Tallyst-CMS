<?php

namespace App\Messenger;

use Psr\Cache\CacheItemPoolInterface;

/**
 * A "last seen" heartbeat for the messenger worker, stored in cache.app. The single source of the
 * cache key + freshness window — written (throttled) by WorkerHeartbeatSubscriber while a worker
 * runs, read by the readiness panel. A fresh heartbeat means a worker is genuinely running; a
 * stale/missing one is reported as "provjeri ručno" (never a hard "dead" claim — a worker just
 * restarted hasn't beaten yet).
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

    public function beat(): void
    {
        $item = $this->cache->getItem(self::KEY);
        $item->set(time());
        $item->expiresAfter(self::TTL);
        $this->cache->save($item);
    }

    public function lastSeen(): ?int
    {
        $item = $this->cache->getItem(self::KEY);
        if (!$item->isHit()) {
            return null;
        }
        $value = $item->get();

        return \is_int($value) ? $value : null;
    }

    public function isFresh(): bool
    {
        $last = $this->lastSeen();

        return null !== $last && (time() - $last) <= self::FRESH_WINDOW;
    }
}
