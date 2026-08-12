<?php

namespace App\Member;

use App\Settings\SettingsManager;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Remembers that the site-wide ceiling on login-link mail turned somebody away, so the OWNER hears
 * it from Tallyst rather than from their mail provider.
 *
 * ⚠ WHY THIS EXISTS AT ALL. The ceiling itself is silent by design — a refused visitor must not be
 * able to tell they were refused, or the login form becomes a way of probing which addresses the
 * site knows. That silence is right for the visitor and useless for the owner, whose sending
 * reputation is the thing actually at risk: a flood of invented addresses bounces hard, a bouncing
 * sender gets frozen, and a frozen sender stops delivering ORDER CONFIRMATIONS too. Somebody under
 * attack has hours, not weeks, and a log line nobody reads is not a warning.
 *
 * ⚠ WRITES ARE THROTTLED TO ONE A MINUTE, ON PURPOSE. Once the ceiling trips, EVERY further request
 * is refused, and nothing bounds how many arrive — writing a row per refusal would make the defence
 * its own denial of service. So the running count lives in the cache (cheap, no database), and the
 * whole record is mirrored to the settings store at most once a minute.
 *
 * The two stores have different jobs and neither is redundant: the settings row SURVIVES a cache
 * clear, which is exactly when the owner would otherwise lose the warning, and the counter is seeded
 * back from it, so clearing the cache costs at most the last minute of counting rather than the
 * whole episode.
 */
class LoginFloodMonitor
{
    /** Not in the settings SCHEMA — this is a recorded observation, never an editable setting. */
    public const string SETTING_KEY = 'member_login_flood';

    private const string COUNTER_KEY = 'member_login_flood_counter';

    /** How long a quiet site keeps showing the warning before it clears itself. */
    public const string SHOW_FOR = '-7 days';

    private const int FLUSH_EVERY_SECONDS = 60;

    public function __construct(
        private readonly SettingsManager $settings,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /** Called once per refused request. Must stay cheap and must never throw into the request. */
    public function recordRefusal(?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();

        try {
            $record = $this->read();
            $item = $this->cache->getItem(self::COUNTER_KEY);

            // Seeded from the persisted count, so a cache clear does not restart the tally at zero.
            $count = $item->isHit() ? (int) $item->get() : (int) ($record['count'] ?? 0);
            ++$count;

            $item->set($count)->expiresAfter(60 * 60 * 24 * 30);
            $this->cache->save($item);

            $lastAt = isset($record['last_at']) ? new \DateTimeImmutable((string) $record['last_at']) : null;
            if (null !== $lastAt && $now->getTimestamp() - $lastAt->getTimestamp() < self::FLUSH_EVERY_SECONDS) {
                return; // counted, not yet written — see the throttling note above
            }

            $this->settings->set(self::SETTING_KEY, json_encode([
                'count' => $count,
                // The start of the episode: kept from the existing record unless this is a new one.
                'first_at' => $this->episodeStart($record, $now),
                'last_at' => $now->format(\DateTimeInterface::ATOM),
            ], \JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            // ⚠ Bookkeeping must never break the page. A visitor whose request was refused still
            // has to get the same ordinary answer as everybody else.
        }
    }

    /**
     * What the readiness panel shows, or null when there is nothing to say.
     *
     * @return array{count: int, first_at: \DateTimeImmutable, last_at: \DateTimeImmutable}|null
     */
    public function recentEpisode(?\DateTimeImmutable $now = null): ?array
    {
        $now ??= new \DateTimeImmutable();
        $record = $this->read();
        if (!isset($record['last_at'])) {
            return null;
        }

        try {
            $lastAt = new \DateTimeImmutable((string) $record['last_at']);
            $firstAt = new \DateTimeImmutable((string) ($record['first_at'] ?? $record['last_at']));
        } catch (\Exception) {
            return null;
        }

        // Clears itself once the site has been quiet for a week — no button, nothing to acknowledge.
        if ($lastAt < $now->modify(self::SHOW_FOR)) {
            return null;
        }

        return ['count' => (int) ($record['count'] ?? 0), 'first_at' => $firstAt, 'last_at' => $lastAt];
    }

    /**
     * A gap of a whole display window means the previous flood is over and this is a new one, so the
     * panel reports "since <today>" rather than pointing at something months old.
     *
     * @param array<string, mixed> $record
     */
    private function episodeStart(array $record, \DateTimeImmutable $now): string
    {
        $firstAt = isset($record['first_at']) ? (string) $record['first_at'] : null;
        $lastAt = isset($record['last_at']) ? (string) $record['last_at'] : null;

        if (null === $firstAt || null === $lastAt) {
            return $now->format(\DateTimeInterface::ATOM);
        }

        try {
            if (new \DateTimeImmutable($lastAt) < $now->modify(self::SHOW_FOR)) {
                return $now->format(\DateTimeInterface::ATOM);
            }
        } catch (\Exception) {
            return $now->format(\DateTimeInterface::ATOM);
        }

        return $firstAt;
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        $raw = $this->settings->get(self::SETTING_KEY);
        if (!is_string($raw) || '' === $raw) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
