<?php

namespace App\Tests\Command;

use App\Command\MemberPruneCommand;
use App\Entity\MemberLoginRequest;
use App\Entity\MemberSession;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The housekeeping nobody was doing.
 *
 * ⚠ Both repositories carried a deleteExpired() with NO caller, so the tables grew forever. These
 * tests exist as much to keep the command WIRED as to check what it removes — an unreferenced
 * cleanup routine is the exact failure this is fixing.
 */
class MemberPruneCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    /** ⚠ Other tests count these tables whole — every row this creates has to go. */
    private function clean(): void
    {
        $this->em->createQuery("DELETE FROM App\Entity\MemberLoginRequest r WHERE r.email LIKE 'prune-%'")->execute();
        $this->em->createQuery("DELETE FROM App\Entity\MemberSession s WHERE s.userIdentifier LIKE 'prune-%'")->execute();
    }

    private function link(string $email, string $expiresAt): void
    {
        $this->em->persist(new MemberLoginRequest(
            $email,
            bin2hex(random_bytes(16)),
            hash('sha256', 'verifier'),
            new \DateTimeImmutable($expiresAt),
        ));
        $this->em->flush();
    }

    private function session(string $identifier, string $lastUsedAt): void
    {
        $this->em->persist(new MemberSession(
            bin2hex(random_bytes(8)),
            bin2hex(random_bytes(16)),
            $identifier,
            new \DateTimeImmutable($lastUsedAt),
            'test-agent',
            '203.0.113.1',
        ));
        $this->em->flush();
    }

    private function prune(array $options = []): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:member:prune'));
        $tester->execute($options);

        return $tester;
    }

    private function countLinks(): int
    {
        return (int) $this->em->createQuery("SELECT COUNT(r.id) FROM App\Entity\MemberLoginRequest r WHERE r.email LIKE 'prune-%'")
            ->getSingleScalarResult();
    }

    private function countSessions(): int
    {
        return (int) $this->em->createQuery("SELECT COUNT(s.series) FROM App\Entity\MemberSession s WHERE s.userIdentifier LIKE 'prune-%'")
            ->getSingleScalarResult();
    }

    /** ⚠ THE ONE THAT MATTERS: expired rows go, live ones stay. */
    public function testItRemovesExpiredLinksAndKeepsLiveOnes(): void
    {
        $this->link('prune-old@example.test', '-2 hours');
        $this->link('prune-older@example.test', '-3 days');
        $this->link('prune-live@example.test', '+20 minutes');

        $this->prune();

        self::assertSame(1, $this->countLinks(), 'the unexpired link must survive');
    }

    /**
     * ⚠ A row here IS somebody's sign-in. The cutoff has to match what the firewall accepts, or
     * members are signed out early with nothing to explain it.
     */
    public function testItRemovesOnlySignInsPastTheFirewallsOwnCutoff(): void
    {
        $this->session('prune-ancient@example.test', '-200 days');
        $this->session('prune-stale@example.test', '-91 days');
        $this->session('prune-recent@example.test', '-89 days');
        $this->session('prune-today@example.test', 'now');

        $this->prune();

        self::assertSame(2, $this->countSessions(), 'a sign-in used 89 days ago is still valid and must stay');
    }

    /** Looking first must change nothing at all. */
    public function testDryRunDeletesNothingButReportsTheSameCounts(): void
    {
        $this->link('prune-old@example.test', '-2 hours');
        $this->session('prune-ancient@example.test', '-200 days');

        $tester = $this->prune(['--dry-run' => true]);

        self::assertSame(1, $this->countLinks());
        self::assertSame(1, $this->countSessions());
        self::assertStringContainsString('nothing was deleted', $tester->getDisplay());
    }

    /** An empty run is a normal outcome on a healthy site, not an error. */
    public function testAQuietSiteSucceedsWithNothingToDo(): void
    {
        $tester = $this->prune();

        self::assertSame(0, $tester->getStatusCode());
    }

    /** The cutoff is adjustable, but the default is the firewall's own 90 days. */
    public function testTheWindowCanBeNarrowed(): void
    {
        $this->session('prune-stale@example.test', '-10 days');

        $this->prune(['--days' => '5']);

        self::assertSame(0, $this->countSessions());
        self::assertSame(90, MemberPruneCommand::SESSION_DAYS, 'the default must stay the firewall lifetime');
    }

    /** ⚠ A nonsense window must refuse, not silently delete everything with a cutoff in the future. */
    public function testAnInvalidWindowIsRefused(): void
    {
        $this->session('prune-today@example.test', 'now');

        $tester = $this->prune(['--days' => '0']);

        self::assertSame(2, $tester->getStatusCode(), 'INVALID, not SUCCESS');
        self::assertSame(1, $this->countSessions(), 'nothing deleted');
    }
}
