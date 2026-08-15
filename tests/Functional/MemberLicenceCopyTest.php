<?php

namespace App\Tests\Functional;

use App\Entity\Member;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tallyst\FormBuilder\Entity\Order;

/**
 * The copy button on a licence key — specifically, the wiring that fails SILENTLY when wrong.
 *
 * ⚠ WHY THESE ASSERTIONS AND NOT "DOES IT COPY". A Stimulus controller whose identifier does not
 * match its registration finds zero targets and does nothing at all: no PHP error, no Twig error,
 * nothing in the server log, and a green test suite. The same goes for a target attribute written
 * without the full controller prefix. That failure has already been shipped once in this project
 * from PHP-built attributes, which is why the rule exists in CLAUDE.md — so the thing worth pinning
 * is that the NAME in the template and the NAME in the bootstrap are the same string.
 *
 * Whether the clipboard itself works is a browser question no PHP test can answer.
 */
class MemberLicenceCopyTest extends WebTestCase
{
    private const string IDENTIFIER = 'formbuilder--copy';

    /** @var int[] */
    private array $orderIds = [];

    protected function tearDown(): void
    {
        $conn = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        foreach ($this->orderIds as $id) {
            $conn->executeStatement('DELETE FROM fb_order WHERE id = ?', [$id]);
        }
        $conn->executeStatement("DELETE FROM `member` WHERE email LIKE 'copy-test-%'");
        $this->orderIds = [];
        parent::tearDown();
    }

    private function pageWithLicence(KernelBrowser $client): \Symfony\Component\DomCrawler\Crawler
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $member = new Member('copy-test-'.uniqid().'@example.com');
        $em->persist($member);

        $order = (new Order())
            ->setMember($member)
            ->setProductName('Arca Backup')
            ->setAmountMinor(3625)
            ->setCurrency('eur')
            ->setStatus(Order::STATUS_PAID)
            ->setLicenseKey('ARCA-1234-5678');
        $em->persist($order);
        $em->flush();
        $this->orderIds[] = $order->getId();

        $client->loginUser($member, 'member');

        return $client->request('GET', '/account/purchase/'.$order->getId());
    }

    /** ⚠ The identifier, the target prefix and the action must all be the SAME string. */
    public function testTheControllerIsWiredWithMatchingNamesThroughout(): void
    {
        $client = static::createClient();
        $crawler = $this->pageWithLicence($client);

        $root = $crawler->filter('[data-controller="'.self::IDENTIFIER.'"]');
        self::assertCount(1, $root, 'the controller root must carry the exact identifier');

        // ⚠ Targets are prefixed with the FULL identifier — data-formbuilder--copy-target, not
        // data-copy-target. A short prefix finds nothing and the button quietly stops working.
        self::assertCount(1, $root->filter('[data-'.self::IDENTIFIER.'-target="value"]'), 'value target');
        self::assertCount(1, $root->filter('[data-'.self::IDENTIFIER.'-target="button"]'), 'button target');
        self::assertSame(
            self::IDENTIFIER.'#copy',
            $root->filter('button')->attr('data-action'),
            'the action must name the same controller',
        );
    }

    /**
     * ⚠ The name in the template must equal the name the bootstrap registers. Nothing at runtime
     * complains when they drift — the button simply becomes decoration.
     */
    public function testTheBootstrapRegistersExactlyThatName(): void
    {
        $bootstrap = file_get_contents(\dirname(__DIR__, 2).'/assets/front_bootstrap.js');

        self::assertStringContainsString("app.register('".self::IDENTIFIER."'", (string) $bootstrap);
    }

    /**
     * ⚠ THE KEY IS READABLE WITHOUT ANY JAVASCRIPT. Somebody who lost their confirmation e-mail is
     * here to recover it; the button only saves a drag of the mouse. It must never become the way in.
     */
    public function testTheKeyIsPlainTextAndNotHiddenBehindTheButton(): void
    {
        $client = static::createClient();
        $crawler = $this->pageWithLicence($client);

        $key = $crawler->filter('.member-licence__key');
        self::assertSame('ARCA-1234-5678', trim($key->text()));

        // Not inside an input that JS has to populate, and not visually hidden.
        self::assertSame('code', $key->getNode(0)->nodeName);
        self::assertStringNotContainsString('hidden', (string) $key->attr('class'));
    }
}
