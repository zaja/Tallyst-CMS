<?php

namespace App\Tests\Messenger;

use App\Messenger\WorkerHeartbeat;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class WorkerHeartbeatTest extends TestCase
{
    public function testNoBeatMeansNotSeenAndNotFresh(): void
    {
        $hb = new WorkerHeartbeat(new ArrayAdapter());

        self::assertNull($hb->lastSeen());
        self::assertFalse($hb->isFresh());
    }

    public function testBeatRecordsAFreshTimestamp(): void
    {
        $hb = new WorkerHeartbeat(new ArrayAdapter());
        $hb->beat();

        $seen = $hb->lastSeen();
        self::assertIsInt($seen);
        self::assertLessThanOrEqual(2, abs(time() - $seen), 'last seen ~ now');
        self::assertTrue($hb->isFresh(), 'a just-written heartbeat is fresh');
    }
}
