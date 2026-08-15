<?php

namespace App\Twig;

use App\Entity\Member;
use App\Member\MemberHelpProviderInterface;
use App\Member\MemberHelpSubject;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `member_help('order', id, label)` → `{template, data}` for the help affordance, or null.
 *
 * ⚠ IT RETURNS A DESCRIPTOR AND RENDERS NOTHING. The template does the including, exactly as the
 * account page does with its blocks — which keeps this out of the business of marking HTML safe and
 * leaves the markup where a theme can override it.
 *
 * The first provider with something to say wins; Core's fallback sits last. A page asks without
 * knowing whether a support module is installed, and gets a sentence or a button or nothing.
 */
class MemberHelpExtension extends AbstractExtension
{
    /**
     * @param iterable<MemberHelpProviderInterface> $providers
     */
    public function __construct(
        private readonly Security $security,
        #[AutowireIterator('app.member_help')]
        private readonly iterable $providers = [],
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('member_help', $this->help(...)),
        ];
    }

    /**
     * @return array{template: string, data: array<string, mixed>}|null
     */
    public function help(string $type, string|int $id, string $label): ?array
    {
        $member = $this->security->getUser();
        if (!$member instanceof Member) {
            return null;
        }

        $subject = new MemberHelpSubject($type, $id, $label);

        $providers = iterator_to_array($this->providers, false);
        usort($providers, static fn (MemberHelpProviderInterface $a, MemberHelpProviderInterface $b): int => $a->getPosition() <=> $b->getPosition());

        foreach ($providers as $provider) {
            $data = $provider->getData($member, $subject);
            if ([] !== $data) {
                return ['template' => $provider->getTemplate(), 'data' => $data];
            }
        }

        return null;
    }
}
