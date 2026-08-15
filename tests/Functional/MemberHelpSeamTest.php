<?php

namespace App\Tests\Functional;

use App\Entity\Member;
use App\Member\MemberHelpProviderInterface;
use App\Member\MemberHelpSubject;
use App\Settings\SettingsManager;
use App\Twig\MemberHelpExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tallyst\FormBuilder\Entity\Order;

/**
 * The place held open for a support module, and Core's answer until one exists.
 *
 * ⚠ THE VALUE HERE IS THAT THE PAGE ASKS INSTEAD OF TELLING. A hardcoded "need help?" sentence would
 * have to be found and replaced in every page that grew one the day support ships. Asking through
 * the seam means the module contributes a provider and takes the place over with a button that opens
 * a request already linked to the purchase — and no page changes. These tests are what prove the
 * takeover works BEFORE the module that will rely on it exists.
 */
class MemberHelpSeamTest extends WebTestCase
{
    /** @var int[] */
    private array $orderIds = [];

    protected function tearDown(): void
    {
        $conn = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        foreach ($this->orderIds as $id) {
            $conn->executeStatement('DELETE FROM fb_order WHERE id = ?', [$id]);
        }
        $conn->executeStatement("DELETE FROM `member` WHERE email LIKE 'help-test-%'");
        $conn->executeStatement("DELETE FROM setting WHERE name = 'support_url'");
        $this->orderIds = [];
        parent::tearDown();
    }

    private function member(KernelBrowser $client): Member
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $member = new Member('help-test-'.uniqid().'@example.com');
        $em->persist($member);
        $em->flush();

        return $member;
    }

    private function order(KernelBrowser $client, Member $owner): Order
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $order = (new Order())
            ->setMember($owner)
            ->setProductName('Arca Backup')
            ->setAmountMinor(3625)
            ->setCurrency('eur')
            ->setStatus(Order::STATUS_PAID);
        $em->persist($order);
        $em->flush();
        $this->orderIds[] = $order->getId();

        return $order;
    }

    private function setSupportUrl(KernelBrowser $client, string $url): void
    {
        $client->getContainer()->get(SettingsManager::class)->set('support_url', $url);
    }

    /**
     * ⚠ NO CONTACT PAGE MEANS NO LINE. A "need help?" link with nowhere to go reads as a broken
     * shop — worse than saying nothing. Same rule as an account block with nothing in it.
     */
    public function testNothingIsShownWhenNoContactPageIsConfigured(): void
    {
        $client = static::createClient();
        $member = $this->member($client);
        $order = $this->order($client, $member);
        $this->setSupportUrl($client, '');
        $client->loginUser($member, 'member');

        $client->request('GET', '/account/purchase/'.$order->getId());

        self::assertStringNotContainsString('member-help', (string) $client->getResponse()->getContent());
    }

    public function testTheSentenceAppearsOnceAContactPageIsSet(): void
    {
        $client = static::createClient();
        $member = $this->member($client);
        $order = $this->order($client, $member);
        $this->setSupportUrl($client, '/contact');
        $client->loginUser($member, 'member');

        $crawler = $client->request('GET', '/account/purchase/'.$order->getId());

        self::assertStringContainsString('Need help with this purchase?', (string) $client->getResponse()->getContent());
        self::assertSame('/contact', $crawler->filter('.member-help a')->attr('href'));
    }

    /**
     * ⚠ Only http(s) or a site-relative path may become an href — the same guard the top bar's
     * social links use, so a javascript: URL typed into a setting cannot reach the page.
     */
    public function testAnUnsafeAddressIsRefusedRatherThanRendered(): void
    {
        $client = static::createClient();
        $member = $this->member($client);
        $order = $this->order($client, $member);
        $this->setSupportUrl($client, 'javascript:alert(1)');
        $client->loginUser($member, 'member');

        $html = (string) $client->request('GET', '/account/purchase/'.$order->getId())->html();

        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString('member-help', $html);
    }

    /**
     * ⚠ THE TAKEOVER, proven before the module that needs it exists. A provider at a lower position
     * replaces Core's sentence entirely — and receives the subject it needs to link a support
     * request to this exact purchase, without Core having described what an order is.
     */
    public function testASupportModuleCanTakeThePlaceOver(): void
    {
        $client = static::createClient();
        $member = $this->member($client);
        $this->setSupportUrl($client, '/contact');

        $module = new class implements MemberHelpProviderInterface {
            public ?MemberHelpSubject $seen = null;

            public function getPosition(): int
            {
                return 10; // ahead of Core's fallback at 100
            }

            public function getTemplate(): string
            {
                return 'member/_help.html.twig';
            }

            public function getData(Member $member, MemberHelpSubject $subject): array
            {
                $this->seen = $subject;

                return ['url' => '/support/new?order='.$subject->id];
            }
        };

        $extension = new MemberHelpExtension(
            $client->getContainer()->get('security.helper'),
            [$module, new \App\Member\DefaultMemberHelpProvider($client->getContainer()->get(SettingsManager::class))],
        );

        $client->loginUser($member, 'member');
        $client->request('GET', '/account'); // establishes the token the extension reads

        $result = $extension->help('order', 42, 'Arca Backup');

        self::assertNotNull($result);
        self::assertSame('/support/new?order=42', $result['data']['url'], "the module's answer wins, not Core's");
        self::assertSame('order', $module->seen->type);
        self::assertSame(42, $module->seen->id);
        self::assertSame('Arca Backup', $module->seen->label, 'it gets a name for the thing, not just an id');
    }
}
