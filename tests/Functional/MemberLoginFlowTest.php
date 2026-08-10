<?php

namespace App\Tests\Functional;

use App\Entity\Member;
use App\Repository\MemberRepository;
use App\Repository\MemberSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The whole way in, end to end: ask for a link, receive it, open it, press the button, arrive at
 * your purchases. The unit tests prove each piece; this one proves they are actually connected.
 */
class MemberLoginFlowTest extends WebTestCase
{
    use MailerAssertionsTrait;

    /**
     * ⚠ Rows created here must not survive the run. Nothing counts customers today, but leaving
     * them behind is exactly how this suite's order tests broke earlier in this feature: a test
     * that counts a whole table is one stray row away from failing for reasons nobody can see.
     */
    protected function tearDown(): void
    {
        $conn = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $conn->executeStatement("DELETE FROM member_login_request WHERE email LIKE 'buyer.%@example.com'");
        $conn->executeStatement("DELETE FROM member_session WHERE user_identifier LIKE 'buyer.%@example.com'");
        $conn->executeStatement("DELETE FROM `member` WHERE email LIKE 'buyer.%@example.com'");
        parent::tearDown();
    }

    private function uniqueEmail(): string
    {
        return 'buyer.'.uniqid().'@example.com';
    }

    /** Pulls the login URL out of the mail that was queued, the way a real buyer would click it. */
    private function linkFromMail(): string
    {
        // The mail goes out through Messenger, so it is QUEUED rather than sent in a test.
        $message = self::getMailerEvent()?->getMessage();
        $body = $message instanceof \Symfony\Component\Mime\Email ? (string) $message->getHtmlBody() : '';
        self::assertMatchesRegularExpression('#/account/link/[a-f0-9]{32}\?v=[a-f0-9]{64}#', (string) $body);
        preg_match('#(/account/link/[a-f0-9]{32}\?v=[a-f0-9]{64})#', (string) $body, $m);

        return $m[1];
    }

    /**
     * ⚠ Twig prints an unresolved translation key as itself, so a page can be completely broken for
     * a human while every functional test stays green — which is exactly what happened here: the
     * strings were nested one level too deep and the visitor saw "theme.member.login.title".
     * Response codes prove nothing about whether a page is readable.
     */
    private function assertNoRawTranslationKeys(string $html, string $where): void
    {
        // Matches the key WITH or WITHOUT its namespace prefix: the failure mode that actually
        // happened was a key that had lost its prefix, so a pattern anchored on "theme." would
        // have sailed straight past it.
        self::assertDoesNotMatchRegularExpression(
            '/(?:theme\.|form\.)?customer\.[a-z_]+\.[a-z_]+/',
            $html,
            $where.' still shows a raw translation key instead of text',
        );
    }

    public function testCustomerPagesShowTextNotTranslationKeys(): void
    {
        $client = static::createClient();

        $client->request('GET', '/account/login');
        $this->assertNoRawTranslationKeys((string) $client->getResponse()->getContent(), 'the login page');

        $crawler = $client->request('GET', '/account/login');
        $client->submit($crawler->filter('form')->form(['email' => $this->uniqueEmail()]));
        $url = $this->linkFromMail(); // before following the redirect — see above
        $client->followRedirect();
        $this->assertNoRawTranslationKeys((string) $client->getResponse()->getContent(), 'the "link sent" page');

        $client->request('GET', $url);
        $this->assertNoRawTranslationKeys((string) $client->getResponse()->getContent(), 'the link page');

        $client->submit($client->getCrawler()->filter('form')->form());
        $client->followRedirect();
        $this->assertNoRawTranslationKeys((string) $client->getResponse()->getContent(), 'the account page');
    }

    public function testTheWholeWayIn(): void
    {
        $client = static::createClient();
        $email = $this->uniqueEmail();

        // 1. Ask for a link.
        $crawler = $client->request('GET', '/account/login');
        $client->submit($crawler->filter('form')->form(['email' => $email]));

        // ⚠ Must REDIRECT, not answer 200. The front runs Turbo, which silently refuses to render
        // a 200 response to a form submission — the visitor would see the untouched form and never
        // learn the mail was sent. No PHP test can exercise Turbo, so this asserts the shape Turbo
        // requires instead.
        self::assertResponseRedirects();

        // ⚠ Read the mail BEFORE following the redirect: the collector holds the CURRENT request's
        // events, and the redirected GET sends nothing.
        self::assertQueuedEmailCount(1);
        $url = $this->linkFromMail();

        $client->followRedirect();

        // And the confirmation has to be READABLE, not merely a 200.
        self::assertStringContainsString(
            'link',
            strtolower((string) $client->getResponse()->getContent()),
            'the page after submitting must tell the visitor a link is on its way',
        );

        // 2. Opening the link changes NOTHING — no account yet, and the link still works.
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();

        /** @var MemberRepository $members */
        $members = $client->getContainer()->get(MemberRepository::class);
        self::assertNull($members->findByEmail($email), 'opening the link must not create the account');

        // 3. Press the button. Only now does anything happen.
        $crawler = $client->getCrawler();
        $client->submit($crawler->filter('form')->form());
        self::assertResponseRedirects('/account');

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertNotNull($members->findByEmail($email), 'confirming creates the account');
    }

    /**
     * ⚠ Signing in must OUTLIVE the browser session, and signing out must really end it.
     *
     * A member who comments or reads weekly cannot be sent to their inbox on every visit, so the
     * sign-in is remembered for 90 days from last use. The other half matters more: signing out has
     * to delete the remembered sign-in, not merely drop the cookie. A cookie that still matches a
     * live row is a working sign-in for anyone holding it.
     */
    public function testSigningInIsRememberedAndSigningOutReallyEndsIt(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/account/login');
        $client->submit($crawler->filter('form')->form(['email' => $this->uniqueEmail()]));
        $url = $this->linkFromMail();
        $client->followRedirect();

        $client->request('GET', $url);
        $client->submit($client->getCrawler()->filter('form')->form());

        $sessions = $client->getContainer()->get(MemberSessionRepository::class);
        self::assertCount(1, $sessions->findAll(), 'confirming records the sign-in as its own row');

        // The remembered sign-in survives losing the session cookie — that is what "stay signed in"
        // means, and a plain session would fail here.
        $client->getCookieJar()->expire('MOCKSESSID');
        $client->request('GET', '/account');
        self::assertResponseIsSuccessful('the remembered sign-in must survive the browser session');

        $client->request('GET', '/account/logout');
        self::assertCount(0, $sessions->findAll(), 'signing out must DELETE the row, not just the cookie');

        $client->request('GET', '/account');
        self::assertResponseRedirects();
    }

    /**
     * ⚠ The reply must not differ for an address nobody has ever used here. If it did, this form
     * would be a way of checking whether a given person bought something.
     */
    public function testAnUnknownAddressGetsTheSameAnswerAsAKnownOne(): void
    {
        $client = static::createClient();

        $known = $this->uniqueEmail();
        /** @var EntityManagerInterface $em */
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist(new Member($known));
        $em->flush();

        $crawler = $client->request('GET', '/account/login');
        $client->submit($crawler->filter('form')->form(['email' => $known]));
        $knownTarget = (string) $client->getResponse()->headers->get('Location');
        $knownBody = (string) $client->followRedirect()->html();

        $crawler = $client->request('GET', '/account/login');
        $client->submit($crawler->filter('form')->form(['email' => $this->uniqueEmail()]));
        $unknownTarget = (string) $client->getResponse()->headers->get('Location');
        $unknownBody = (string) $client->followRedirect()->html();

        self::assertSame($knownTarget, $unknownTarget, 'even the redirect target must not differ');

        self::assertSame($knownBody, $unknownBody, 'the two replies must be byte-identical');
    }

    /** A second press of the same link fails — it was spent the first time. */
    public function testALinkCannotBeUsedTwice(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/account/login');
        $client->submit($crawler->filter('form')->form(['email' => $this->uniqueEmail()]));
        $url = $this->linkFromMail(); // before following the redirect — see above
        $client->followRedirect();

        $client->request('GET', $url);
        $client->submit($client->getCrawler()->filter('form')->form());
        self::assertResponseRedirects('/account');

        // Same URL again, fresh session.
        $client->request('GET', '/account/logout');
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(
            'name="v"',
            (string) $client->getResponse()->getContent(),
            'a spent link must not offer the confirm button again',
        );
    }
}
