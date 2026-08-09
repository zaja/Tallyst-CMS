<?php

namespace App\Security;

use App\Customer\CustomerLoginLinkService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Signs a buyer in when they CONFIRM an e-mail login link.
 *
 * ⚠ It answers only to POST. The GET that opens the link renders a button and changes nothing —
 * corporate mail filters and some clients fetch every URL in a message before a human sees it, and
 * if opening authenticated (or even just spent the token) those customers would be locked out
 * every time, reproducibly. The button press is the moment anything happens.
 *
 * ⚠ Written as a firewall authenticator rather than by building a token by hand, because the
 * firewall is what makes session persistence, logout and the security context behave. Hand-rolled
 * login is the kind of thing that looks fine and then breaks quietly a release later.
 *
 * There is no password here and no password check anywhere: confirming the link IS the proof, so
 * the passport is self-validating.
 */
class CustomerLoginLinkAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly CustomerLoginLinkService $links,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return 'customer_login_link' === $request->attributes->get('_route') && $request->isMethod('POST');
    }

    public function authenticate(Request $request): Passport
    {
        $customer = $this->links->confirm(
            (string) $request->attributes->get('selector'),
            (string) $request->request->get('v', ''),
        );

        if (null === $customer) {
            // One message for every failure — expired, already used, wrong or never existed. The
            // differences between them are exactly what an attacker would want to read.
            throw new CustomUserMessageAuthenticationException('customer.login.link_invalid');
        }

        return new SelfValidatingPassport(
            new UserBadge($customer->getUserIdentifier(), static fn (): object => $customer),
            [new CsrfTokenBadge('customer_link', (string) $request->request->get('_token'))],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return new RedirectResponse($this->urls->generate('customer_account'));
    }

    /**
     * Where an unauthenticated visitor to /account lands: the page that asks for a link. A bare 401
     * would be technically correct and useless to a buyer who just wants to see what they bought.
     */
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new RedirectResponse($this->urls->generate('customer_login'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // Back to the link page, which renders the same "this link no longer works" for every cause.
        return new RedirectResponse($this->urls->generate('customer_login_link', [
            'selector' => (string) $request->attributes->get('selector'),
        ]));
    }
}
