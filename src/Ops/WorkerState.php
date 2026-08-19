<?php

namespace App\Ops;

use App\Messenger\ConsumableTransports;
use App\Messenger\WorkerHeartbeat;

/**
 * What the background worker was doing, so a message can be written for the owner's actual situation
 * rather than the same block every time.
 *
 * ⚠ WHY IT MATTERS THAT THE MESSAGE DIFFERS. An owner who is handed a full setup block on every
 * upgrade — one they have already followed, for a worker that is already running — stops reading it.
 * And then they miss the upgrade where something genuinely is wrong. Repeating advice somebody does
 * not need is how you lose their attention for the time they do.
 *
 * ⚠ THE STATE MUST BE READ BEFORE `cache:clear`. The heartbeat lives in cache.app and
 * `app:upgrade:finalize` clears the cache twice; reading afterwards would report "no worker" on
 * every upgrade, which is precisely the always-the-same-block failure this exists to avoid. It is
 * also the more truthful question: what the owner needs to hear depends on the worker they had
 * BEFORE the upgrade.
 *
 * Same source the readiness panel uses — no second opinion about what "running" means.
 */
final readonly class WorkerState
{
    private function __construct(
        public bool $running,
        /** @var list<string> queues it should consume but does not */
        public array $missingQueues,
    ) {
    }

    public static function read(WorkerHeartbeat $heartbeat, ConsumableTransports $transports): self
    {
        if (!$heartbeat->isFresh()) {
            return new self(false, []);
        }

        return new self(true, $transports->missingFrom($heartbeat->transports()));
    }

    /** Running and watching everything it should — the owner needs one line, not a lecture. */
    public function isComplete(): bool
    {
        return $this->running && [] === $this->missingQueues;
    }

    /** Running, but a queue it should be consuming is missing from its command. */
    public function isIncomplete(): bool
    {
        return $this->running && [] !== $this->missingQueues;
    }

    /** No worker at all — the only case that needs the full block. */
    public function isAbsent(): bool
    {
        return !$this->running;
    }
}
