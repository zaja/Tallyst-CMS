<?php

namespace App\Tests\Command;

use App\Entity\Page;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Locks the install↔demo↔delete symmetry for the home page (v1.5.1): the demo adopts the
 * install baseline home (flags it is_demo=true), so `--clear` deletes it with the other demo
 * pages — and clearDemo then RE-CREATES the install baseline home (non-demo, editable) via
 * BaselineSeeder, so removing the demo leaves a real home like a clean install (not the
 * transient PageController::home() fallback). Lightweight: an entity + `--clear`, no full seed
 * (no Vich/imagick).
 */
class DemoSeedHomeRestoreTest extends KernelTestCase
{
    private ?int $cleanupHomeId = null;

    public function testClearRestoresTheBaselineHome(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        // Start from a demo home (is_demo=true), exactly as ensurePages would have left it after
        // adopting the install baseline. Clear any stray 'home' first (slug is unique).
        $em->getConnection()->executeStatement("DELETE FROM page WHERE slug = 'home'");
        $demoHome = (new Page('Home', 'home'))
            ->setStatus(Page::STATUS_PUBLISHED)
            ->setContent('<h1 class="display-1">Demo landing</h1>')
            ->setIsDemo(true);
        $em->persist($demoHome);
        $em->flush();
        $demoHomeId = $demoHome->getId();

        // Run the uninstaller.
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:demo:seed'));
        $tester->execute(['--clear' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $em->clear();

        // The demo home is gone, and a NON-demo baseline home was re-created in its place.
        self::assertNull($em->find(Page::class, $demoHomeId), 'the demo home must be deleted by --clear');

        $home = $em->getRepository(Page::class)->findOneBy(['slug' => 'home']);
        self::assertNotNull($home, 'clearDemo must re-create the baseline home');
        self::assertFalse($home->isDemo(), 're-created home is non-demo (permanent, editable)');
        self::assertSame(Page::STATUS_PUBLISHED, $home->getStatus());
        self::assertStringContainsString('app:install', (string) $home->getContent(), 'the install baseline placeholder content');

        $this->cleanupHomeId = $home->getId();
    }

    protected function tearDown(): void
    {
        if (null !== $this->cleanupHomeId && null !== self::$kernel) {
            $conn = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
            $conn->executeStatement('DELETE FROM page WHERE id = ?', [$this->cleanupHomeId]);
            $this->cleanupHomeId = null;
        }

        parent::tearDown();
    }
}
