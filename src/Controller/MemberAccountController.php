<?php

namespace App\Controller;

use App\Member\MemberAccountSectionInterface;
use App\Member\MemberLoginLinkService;
use App\Email\EmailSender;
use App\Entity\Member;
use App\Settings\SettingsManager;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A member's own corner of the site: ask for a link, confirm it, see whatever this site shows them.
 *
 * ⚠ THE PAGE THAT ASKS FOR A LINK ALWAYS ANSWERS THE SAME WAY, whether or not the address is known.
 * Anything else turns this form into a tool for checking whether a given person bought something —
 * type an address, read the difference in the reply. That includes the throttled case: being told
 * "too many requests" would confirm the address just as loudly.
 */
class MemberAccountController extends AbstractController
{
    /**
     * @param iterable<MemberAccountSectionInterface> $sections
     */
    public function __construct(
        private readonly MemberLoginLinkService $links,
        private readonly EmailSender $emails,
        private readonly SettingsManager $settings,
        private readonly LoggerInterface $logger,
        #[AutowireIterator('app.member_account_section')]
        private readonly iterable $sections = [],
    ) {
    }

    #[Route('/account', name: 'member_account', methods: ['GET'])]
    public function index(): Response
    {
        /** @var Member $member */
        $member = $this->getUser();

        $blocks = [];
        foreach ($this->sections as $section) {
            $data = $section->getData($member);

            // ⚠ A block with nothing in it is NOT rendered. Most new members have never bought
            // anything — anyone can sign up, and buying is one thing a site may offer, not the
            // reason the account exists — so an empty purchases list would be the first thing they
            // ever saw. A section says "I have nothing" by returning empty data.
            if ([] === array_filter($data, static fn (mixed $v): bool => [] !== $v && null !== $v)) {
                continue;
            }

            $blocks[] = [
                'position' => $section->getPosition(),
                'template' => $section->getTemplate(),
                'data' => $data,
            ];
        }
        usort($blocks, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return $this->render('member/account.html.twig', [
            'member' => $member,
            'blocks' => $blocks,
        ]);
    }

    #[Route('/account/login', name: 'member_login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        if ($this->getUser() instanceof Member) {
            return $this->redirectToRoute('member_account');
        }

        if ($request->isMethod('POST')) {
            $email = trim((string) $request->request->get('email'));

            if ('' !== $email && $this->isCsrfTokenValid('member_login', (string) $request->request->get('_token'))) {
                $this->issueLink($email, $request);
            }

            // ⚠ POST/REDIRECT/GET, and it is not optional here. The public front runs Turbo
            // (turbo-core, fetch: eager), and Turbo REFUSES to render a 200 HTML response to a form
            // submission — it expects a redirect. Returning the confirmation page directly means the
            // visitor sees the form again, unchanged, while the mail really did go out: they retype
            // the address, get a second link, and eventually give up. It also stops a refresh from
            // re-submitting.
            //
            // The flag is in the URL and gives nothing away: it is set for every submission, known
            // address or not.
            return $this->redirectToRoute('member_login', ['sent' => 1]);
        }

        return $this->render('member/login.html.twig', [
            // Always the same answer. Not "we sent it" versus "no such account", and no visible
            // difference when throttled either — see the class docblock.
            'sent' => $request->query->getBoolean('sent'),
        ]);
    }

    /**
     * The link's landing page — a button, and nothing else.
     *
     * ⚠ GET ONLY LOOKS. It does not spend the link and does not sign anyone in. Mail filters fetch
     * every URL in a message before a human sees it, so acting here would lock those members out
     * every time, reproducibly. The POST is handled by MemberLoginLinkAuthenticator, which is why
     * this method never sees one.
     */
    #[Route('/account/link/{selector}', name: 'member_login_link', methods: ['GET', 'POST'], requirements: ['selector' => '[a-f0-9]{32}'])]
    public function confirmLink(string $selector, Request $request): Response
    {
        $verifier = (string) $request->query->get('v', '');

        return $this->render('member/link.html.twig', [
            'valid' => null !== $this->links->resolve($selector, $verifier),
            'selector' => $selector,
            'verifier' => $verifier,
        ]);
    }

    #[Route('/account/logout', name: 'member_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('Intercepted by the member firewall.');
    }

    /**
     * Issues and mails a link. Everything in here fails quietly: the caller must not be able to
     * tell an unknown address, a throttled one and a mail problem apart.
     */
    private function issueLink(string $email, Request $request): void
    {
        try {
            if (!$this->links->isAllowedToRequest($email)) {
                return;
            }

            $link = $this->links->issue($email);

            $url = $this->generateUrl(
                'member_login_link',
                ['selector' => $link->selector, 'v' => $link->verifier],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );

            $this->emails->send('member_login', [
                'login_url' => $url,
                'site_name' => (string) $this->settings->get('site_name'),
            ], $link->email);
        } catch (\Throwable $e) {
            // Logged, never surfaced — the visitor gets the same page either way.
            $this->logger->error('Member login link failed.', ['exception' => $e]);
        }
    }
}
