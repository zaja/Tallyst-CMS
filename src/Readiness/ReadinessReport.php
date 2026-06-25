<?php

namespace App\Readiness;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Aggregates every tagged readiness provider into a grouped report + summary counts. Mirrors the
 * SettingsRegistry / dashboard-widget IoC pattern.
 */
class ReadinessReport
{
    /**
     * @param iterable<ReadinessCheckProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('app.readiness_check')]
        private readonly iterable $providers,
    ) {
    }

    /**
     * @return array{
     *   groups: array<string, list<Check>>,
     *   summary: array{ok:int, warning:int, problem:int, manual:int, total:int},
     * }
     */
    public function build(): array
    {
        $groups = [];
        $summary = ['ok' => 0, 'warning' => 0, 'problem' => 0, 'manual' => 0, 'total' => 0];

        foreach ($this->providers as $provider) {
            foreach ($provider->getChecks() as $check) {
                $groups[$check->group][] = $check;
                ++$summary[$check->status->value];
                ++$summary['total'];
            }
        }

        return ['groups' => $groups, 'summary' => $summary];
    }
}
