<?php

namespace App\Member;

use App\Entity\Member;
use App\Settings\SettingsManager;

/**
 * Core's answer until a support module exists: one sentence pointing at the site's own contact page.
 *
 * ⚠ It sits LAST (position 100) so any real provider outranks it without having to know what number
 * to beat.
 *
 * ⚠ NO CONTACT PAGE CONFIGURED MEANS NO LINE AT ALL. Tallyst has no built-in notion of a contact
 * page — the owner builds one as an ordinary page with a form on it — so there is nothing sensible
 * to guess. Guessing `/contact` would work on the sites that happened to use that slug and be a dead
 * link everywhere else, which is worse than silence: a broken "need help?" link is read as a broken
 * shop. The address is `support_url` in Settings → General.
 */
final readonly class DefaultMemberHelpProvider implements MemberHelpProviderInterface
{
    public function __construct(private SettingsManager $settings)
    {
    }

    public function getPosition(): int
    {
        return 100;
    }

    public function getTemplate(): string
    {
        return 'member/_help.html.twig';
    }

    public function getData(Member $member, MemberHelpSubject $subject): array
    {
        $url = trim((string) $this->settings->get('support_url'));

        // ⚠ Same guard as the top bar's social links: only http(s) or a site-relative path may
        // become an href, so a javascript: or data: URL typed into a setting cannot reach the page.
        if ('' === $url || !$this->isSafeUrl($url)) {
            return [];
        }

        return ['url' => $url, 'subject' => $subject];
    }

    private function isSafeUrl(string $url): bool
    {
        return str_starts_with($url, '/')
            || str_starts_with($url, 'http://')
            || str_starts_with($url, 'https://');
    }
}
