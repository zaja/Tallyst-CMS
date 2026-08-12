<?php

namespace App\Tests\Member;

use App\Member\LoginFloodMonitor;
use App\Settings\SettingsManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * The record behind the readiness warning: what the owner is told, and how little it costs to tell
 * them.
 */
class LoginFloodMonitorTest extends TestCase
{
    private ArrayAdapter $cache;
    /** @var array<string, mixed> */
    private array $stored = [];

    private function monitor(): LoginFloodMonitor
    {
        $settings = $this->createStub(SettingsManager::class);
        $settings->method('get')->willReturnCallback(fn (string $k): mixed => $this->stored[$k] ?? null);
        $settings->method('set')->willReturnCallback(function (string $k, mixed $v): void {
            $this->stored[$k] = $v;
        });

        return new LoginFloodMonitor($settings, $this->cache);
    }

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
        $this->stored = [];
    }

    public function testNothingIsReportedOnAQuietSite(): void
    {
        self::assertNull($this->monitor()->recentEpisode());
    }

    public function testARefusalIsRecordedAndSurfaced(): void
    {
        $monitor = $this->monitor();
        $monitor->recordRefusal(new \DateTimeImmutable('2026-08-12 10:00:00'));

        $episode = $monitor->recentEpisode(new \DateTimeImmutable('2026-08-12 10:05:00'));

        self::assertNotNull($episode);
        self::assertSame(1, $episode['count']);
        self::assertSame('2026-08-12 10:00:00', $episode['last_at']->format('Y-m-d H:i:s'));
    }

    /**
     * ⚠ THE ONE THAT KEEPS THE DEFENCE FROM BECOMING THE ATTACK. Once the ceiling trips every
     * further request is refused and nothing bounds how many arrive, so a write per refusal would
     * hand the attacker a way to hammer the database instead of the mail provider.
     */
    public function testManyRefusalsInOneMinuteCostOneWrite(): void
    {
        $writes = 0;

        $settings = $this->createStub(SettingsManager::class);
        $settings->method('get')->willReturnCallback(fn (string $k): mixed => $this->stored[$k] ?? null);
        $settings->method('set')->willReturnCallback(function (string $k, mixed $v) use (&$writes): void {
            ++$writes;
            $this->stored[$k] = $v;
        });
        $monitor = new LoginFloodMonitor($settings, $this->cache);

        // 500 refusals spread across the same minute — a real flood arrives far faster than this.
        $at = new \DateTimeImmutable('2026-08-12 10:00:00');
        for ($i = 0; $i < 500; ++$i) {
            $monitor->recordRefusal($at->modify(\sprintf('+%d seconds', $i % 59)));
        }

        self::assertSame(1, $writes, '500 refusals inside one minute must not be 500 database writes');
    }

    /** The count keeps rising across the throttled writes — the owner sees the real number, not one. */
    public function testTheCountKeepsRisingWhileWritesAreThrottled(): void
    {
        $monitor = $this->monitor();

        $monitor->recordRefusal(new \DateTimeImmutable('2026-08-12 10:00:00'));
        for ($i = 0; $i < 9; ++$i) {
            $monitor->recordRefusal(new \DateTimeImmutable('2026-08-12 10:00:10'));
        }
        // A minute later the accumulated total is written out.
        $monitor->recordRefusal(new \DateTimeImmutable('2026-08-12 10:01:30'));

        $episode = $monitor->recentEpisode(new \DateTimeImmutable('2026-08-12 10:02:00'));
        self::assertSame(11, $episode['count']);
    }

    /**
     * ⚠ Clearing the cache is routine on upgrade, and it must not erase what the owner has not seen
     * yet. The persisted record seeds the counter back.
     */
    public function testClearingTheCacheDoesNotRestartTheTally(): void
    {
        $monitor = $this->monitor();
        $monitor->recordRefusal(new \DateTimeImmutable('2026-08-12 10:00:00'));
        $monitor->recordRefusal(new \DateTimeImmutable('2026-08-12 10:02:00'));
        self::assertSame(2, $monitor->recentEpisode(new \DateTimeImmutable('2026-08-12 10:02:30'))['count']);

        $this->cache->clear();

        $monitor->recordRefusal(new \DateTimeImmutable('2026-08-12 10:04:00'));

        $episode = $monitor->recentEpisode(new \DateTimeImmutable('2026-08-12 10:04:30'));
        self::assertSame(3, $episode['count'], 'counting resumes from the persisted total, not from zero');
    }

    /** After a quiet week the notice clears itself — nothing to acknowledge, no button. */
    public function testTheWarningClearsItselfAfterAQuietWeek(): void
    {
        $monitor = $this->monitor();
        $monitor->recordRefusal(new \DateTimeImmutable('2026-08-01 10:00:00'));

        self::assertNotNull($monitor->recentEpisode(new \DateTimeImmutable('2026-08-05 10:00:00')));
        self::assertNull($monitor->recentEpisode(new \DateTimeImmutable('2026-08-20 10:00:00')));
    }

    /** A fresh flood months later reports itself as new, not as one long episode since spring. */
    public function testALaterFloodStartsAFreshEpisode(): void
    {
        $monitor = $this->monitor();
        $monitor->recordRefusal(new \DateTimeImmutable('2026-05-01 10:00:00'));
        $monitor->recordRefusal(new \DateTimeImmutable('2026-08-12 10:00:00'));

        $episode = $monitor->recentEpisode(new \DateTimeImmutable('2026-08-12 10:01:00'));
        self::assertSame('2026-08-12', $episode['first_at']->format('Y-m-d'));
    }

    /**
     * ⚠ Bookkeeping must never break the page. A visitor whose request was refused still gets the
     * same ordinary answer as everybody else, even if the store is unwritable.
     */
    public function testAFailingStoreDoesNotThrowIntoTheRequest(): void
    {
        $settings = $this->createStub(SettingsManager::class);
        $settings->method('get')->willThrowException(new \RuntimeException('database gone'));

        $monitor = new LoginFloodMonitor($settings, $this->cache);
        $monitor->recordRefusal();

        self::assertTrue(true, 'no exception escaped');
    }
}
