<?php

namespace App\Messenger;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * The queues a worker on this install is expected to consume.
 *
 * ⚠ IT READS THE LIST, IT DOES NOT RESTATE IT. Every guard in this project that enumerated what its
 * author remembered has eventually missed something — admin screens, translation keys, front
 * controllers. The transports are already declared once, in messenger.yaml plus whatever the
 * Scheduler registers, and the container knows all of them; a second hand-written copy here would go
 * stale the first time somebody adds a queue and forgets this file exists.
 *
 * ⚠ `failed` IS EXCLUDED DELIBERATELY. It is a store for messages that already went wrong, drained
 * with `messenger:failed:retry` by a person looking at them — a worker that consumed it would retry
 * failures in an endless loop.
 */
final readonly class ConsumableTransports
{
    private const NEVER_CONSUMED = ['failed'];

    public function __construct(
        #[Autowire(service: 'messenger.receiver_locator')]
        private ContainerInterface $receivers,
    ) {
    }

    /**
     * @return list<string> short transport names, e.g. ['async', 'scheduler_order_maintenance']
     */
    public function expected(): array
    {
        if (!$this->receivers instanceof ServiceLocator) {
            return [];
        }

        $names = [];
        foreach (array_keys($this->receivers->getProvidedServices()) as $id) {
            // The locator carries each transport twice: under its service id and under its short
            // name. Only the short name is what a worker command takes.
            if (str_starts_with($id, 'messenger.transport.')) {
                continue;
            }
            if (\in_array($id, self::NEVER_CONSUMED, true)) {
                continue;
            }

            $names[] = $id;
        }

        sort($names);

        return $names;
    }

    /**
     * Expected queues the last-seen worker was NOT consuming.
     *
     * ⚠ An empty `$consuming` means "not known yet" — a worker from an older release, or a cache
     * cleared moments ago — and must never be read as "consuming nothing". Returning no missing
     * queues for an unknown worker is the safe direction: the caller reports uncertainty instead of
     * accusing a healthy install.
     *
     * @param list<string> $consuming
     *
     * @return list<string>
     */
    public function missingFrom(array $consuming): array
    {
        if ([] === $consuming) {
            return [];
        }

        return array_values(array_diff($this->expected(), $consuming));
    }
}
