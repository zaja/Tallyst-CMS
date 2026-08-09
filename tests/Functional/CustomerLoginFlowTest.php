<?php

namespace App\Tests\Functional;

use App\Entity\Customer;
use App\Repository\CustomerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The whole way in, end to end: ask for a link, receive it, open it, press the button, arrive at
 * your purchases. The unit tests prove each piece; this one proves they are actually connected.
 */
class CustomerLoginFlowTest extends WebTestCase
{
    use MailerAssertionsTrait;

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
     * strings were nested one level too deep and the visitor saw "theme.customer.login.title".
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

        /** @var CustomerRepository $customers */
        $customers = $client->getContainer()->get(CustomerRepository::class);
        self::assertNull($customers->findByEmail($email), 'opening the link must not create the account');

        // 3. Press the button. Only now does anything happen.
        $crawler = $client->getCrawler();
        $client->submit($crawler->filter('form')->form());
        self::assertResponseRedirects('/account');

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertNotNull($customers->findByEmail($email), 'confirming creates the account');
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
        $em->persist(new Customer($known));
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
