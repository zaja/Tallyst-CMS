<?php

namespace App\Tests\Command;

use App\Command\InstallFinalizeCommand;
use App\Install\BaselineSeeder;
use App\Repository\UserRepository;
use App\Settings\SettingsManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Covers only the defensive input-validation paths (which return before any DB work) — the
 * seed/admin happy path needs a real DB and is exercised by the user's smoke test.
 */
class InstallFinalizeCommandTest extends TestCase
{
    private function tester(): CommandTester
    {
        return new CommandTester(new InstallFinalizeCommand(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(UserRepository::class),
            $this->createStub(UserPasswordHasherInterface::class),
            $this->createStub(BaselineSeeder::class),
            $this->createStub(SettingsManager::class),
        ));
    }

    protected function setUp(): void
    {
        // Ensure the password env var is absent unless a test sets it.
        putenv('TALLYST_ADMIN_PASSWORD');
        unset($_SERVER['TALLYST_ADMIN_PASSWORD']);
    }

    protected function tearDown(): void
    {
        putenv('TALLYST_ADMIN_PASSWORD');
        unset($_SERVER['TALLYST_ADMIN_PASSWORD']);
    }

    public function testRejectsAnInvalidEmail(): void
    {
        putenv('TALLYST_ADMIN_PASSWORD=longenough');
        $tester = $this->tester();
        $tester->execute(['--email' => 'not-an-email', '--role' => 'ROLE_ADMIN']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
    }

    public function testRejectsAnUnknownRole(): void
    {
        putenv('TALLYST_ADMIN_PASSWORD=longenough');
        $tester = $this->tester();
        $tester->execute(['--email' => 'admin@test.local', '--role' => 'ROLE_SUPERHERO']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('Invalid role', $tester->getDisplay());
    }

    public function testRejectsAMissingPassword(): void
    {
        $tester = $this->tester();
        $tester->execute(['--email' => 'admin@test.local', '--role' => 'ROLE_ADMIN']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('TALLYST_ADMIN_PASSWORD', $tester->getDisplay());
    }

    public function testRejectsAShortPassword(): void
    {
        putenv('TALLYST_ADMIN_PASSWORD=short');
        $tester = $this->tester();
        $tester->execute(['--email' => 'admin@test.local', '--role' => 'ROLE_ADMIN']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
    }
}
