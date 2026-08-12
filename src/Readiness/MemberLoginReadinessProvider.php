<?php

namespace App\Readiness;

use App\Member\LoginFloodMonitor;
use App\Settings\SettingsManager;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Tells the owner that something has been hammering the login-link form.
 *
 * ⚠ THIS IS THE ONLY PLACE THE OWNER CAN FIND OUT IN TIME. The site-wide ceiling has to refuse
 * silently — a visitor who could tell they were refused could use the form to probe which addresses
 * the site knows — so nothing on the public side shows it, and a log line is not a warning because
 * nobody reads logs. Left unseen, the first news of a flood arrives from the mail provider, in the
 * form of a frozen sending account, at which point the shop is also failing to deliver ORDER
 * CONFIRMATIONS and nothing can be fixed backwards.
 *
 * ⚠ It reports what it MEASURED and no more: refusals, not "an attack". A launch day where real
 * people crowd the form produces the same row, and the honest reading of it is "look at this", not
 * "you are under attack". Same rule as the rest of the panel — never claim more than can be proven.
 */
class MemberLoginReadinessProvider implements ReadinessCheckProviderInterface
{
    private const string GROUP = 'admin.readiness.group.security';

    public function __construct(
        private readonly LoginFloodMonitor $flood,
        private readonly SettingsManager $settings,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** @param array<string, string|int> $params */
    private function t(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $params, 'admin');
    }

    public function getChecks(): iterable
    {
        $episode = $this->flood->recentEpisode();

        // ⚠ Nothing to report is NOT a row. A quiet site must not carry a permanent green line about
        // an attack that never happened — the panel is read by someone deciding what to act on.
        if (null === $episode) {
            return;
        }

        $format = (string) ($this->settings->get('date_format') ?: 'd.m.Y. H:i');

        yield Check::warning(
            $this->t(self::GROUP),
            $this->t('admin.readiness.login_flood.label'),
            $this->t('admin.readiness.login_flood.detail', [
                '%count%' => $episode['count'],
                '%since%' => $episode['first_at']->format($format),
                '%last%' => $episode['last_at']->format($format),
            ]),
            $this->t('admin.readiness.login_flood.fix'),
        );
    }
}
