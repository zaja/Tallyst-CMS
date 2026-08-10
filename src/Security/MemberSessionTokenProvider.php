<?php

namespace App\Security;

use App\Entity\MemberSession;
use App\Repository\MemberSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\RememberMe\PersistentToken;
use Symfony\Component\Security\Core\Authentication\RememberMe\PersistentTokenInterface;
use Symfony\Component\Security\Core\Authentication\RememberMe\TokenProviderInterface;
use Symfony\Component\Security\Core\Exception\TokenNotFoundException;

/**
 * Stores remembered member sign-ins in our own table instead of Symfony's.
 *
 * ⚠ WHY NOT THE BUILT-IN STORE. Symfony's own provider has a fixed set of columns and no room for a
 * device or an address. Recording those is not a feature we are building today — there is no
 * "your devices" screen — but the SHAPE has to be right from the start: adding them later means
 * changing how every existing sign-in is stored, and re-issuing everybody's.
 *
 * Symfony keeps doing the parts it does well: writing and refreshing the cookie, and clearing the
 * token on sign-out. Only the storage is ours.
 *
 * ⚠ The token value is held as Symfony holds it, because the framework compares it directly against
 * the cookie. That is its standard model and this class does not weaken it: the value is 64 random
 * bytes, single-purpose, and useless without its series.
 */
final readonly class MemberSessionTokenProvider implements TokenProviderInterface
{
    public function __construct(
        private MemberSessionRepository $sessions,
        private EntityManagerInterface $em,
        private RequestStack $requests,
    ) {
    }

    public function loadTokenBySeries(string $series): PersistentTokenInterface
    {
        $session = $this->sessions->find($series);
        if (null === $session) {
            throw new TokenNotFoundException('No remembered sign-in for this series.');
        }

        // ⚠ Four arguments, not five: this Symfony version's PersistentToken has no leading class
        // parameter. Symfony's own handler probes for it with method_exists() because the signature
        // has differed between versions — passing the wrong arity puts the class name into the
        // identifier and every remembered sign-in 500s on use.
        return new PersistentToken(
            $session->getUserIdentifier(),
            $session->getSeries(),
            $session->getTokenValue(),
            \DateTime::createFromImmutable($session->getLastUsedAt()),
        );
    }

    /**
     * ⚠ Signing out lands here, and it must really delete. A cookie left pointing at a live row is
     * still a working sign-in for whoever holds it.
     */
    public function deleteTokenBySeries(string $series): void
    {
        $session = $this->sessions->find($series);
        if (null !== $session) {
            $this->em->remove($session);
            $this->em->flush();
        }
    }

    public function updateToken(string $series, #[\SensitiveParameter] string $tokenValue, \DateTimeInterface $lastUsed): void
    {
        $session = $this->sessions->find($series);
        if (null === $session) {
            throw new TokenNotFoundException('No remembered sign-in for this series.');
        }

        // Every use pushes the clock forward, which is what makes the 90 days run from LAST USE:
        // somebody who visits weekly never has to ask for a new link.
        $session->refresh($tokenValue, \DateTimeImmutable::createFromInterface($lastUsed));
        $this->em->flush();
    }

    public function createNewToken(PersistentTokenInterface $token): void
    {
        $request = $this->requests->getCurrentRequest();

        $this->em->persist(new MemberSession(
            $token->getSeries(),
            $token->getTokenValue(),
            $token->getUserIdentifier(),
            \DateTimeImmutable::createFromInterface($token->getLastUsed()),
            $request?->headers->get('User-Agent'),
            $request?->getClientIp(),
        ));
        $this->em->flush();
    }
}
