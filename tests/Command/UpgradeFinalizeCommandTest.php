<?php

namespace App\Tests\Command;

use App\Command\UpgradeFinalizeCommand;
use App\Console\ConsoleStepRunner;
use App\Install\DatabaseBackupService;
use App\Install\DatabaseProber;
use App\Install\InstallStateDetector;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Covers the pre-flight + backup-policy GUARDS in isolation. The deterministic post-guard steps
 * (cache:clear/migrate/assets) run as real subprocesses, so the happy path + ordering are proven by
 * the dev smoke, not here. ConsoleStepRunner/DatabaseBackupService are final (un-mockable), but the
 * tested guard paths either return before reaching them or skip them (--no-backup) — only the
 * non-final Connection is mocked, to drive ping success/failure.
 */
class UpgradeFinalizeCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/tallyst-upgrade-test-'.uniqid('', true);
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        if (is_file($this->tmpDir.'/.env.local')) {
            unlink($this->tmpDir.'/.env.local');
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    private function writeEnvLocal(string $contents): void
    {
        file_put_contents($this->tmpDir.'/.env.local', $contents);
    }

    private function makeCommand(Connection $connection): UpgradeFinalizeCommand
    {
        return new UpgradeFinalizeCommand(
            $this->tmpDir,
            $connection,
            new InstallStateDetector(),
            new DatabaseProber(),
            new ConsoleStepRunner($this->tmpDir),                       // real, but any step in tmpDir fails fast
            new DatabaseBackupService('mysql://u:p@127.0.0.1:3306/db', $this->tmpDir),
        );
    }

    public function testPreflightFailsWithoutDatabaseUrl(): void
    {
        // No .env.local at all → envLocalHasDatabaseUrl is false → hard stop before any collaborator.
        $tester = new CommandTester($this->makeCommand($this->createStub(Connection::class)));
        $code = $tester->execute([], ['interactive' => false]);

        self::assertSame(1, $code);
        self::assertStringContainsString('.env.local has no DATABASE_URL', $tester->getDisplay());
    }

    public function testPreflightFailsWhenDatabaseUnreachableWithoutForce(): void
    {
        $this->writeEnvLocal("DATABASE_URL=mysql://u:p@127.0.0.1:3306/db\n");

        $conn = $this->createStub(Connection::class);
        $conn->method('executeQuery')->willThrowException(new \RuntimeException('connection refused'));

        $tester = new CommandTester($this->makeCommand($conn));
        $code = $tester->execute([], ['interactive' => false]);

        self::assertSame(1, $code);
        self::assertStringContainsString('Database is not reachable', $tester->getDisplay());
    }

    public function testForceBypassesUnreachableDatabaseAndProceedsPastPreflight(): void
    {
        $this->writeEnvLocal("DATABASE_URL=mysql://u:p@127.0.0.1:3306/db\n");

        $conn = $this->createStub(Connection::class);
        $conn->method('executeQuery')->willThrowException(new \RuntimeException('connection refused'));

        // --force past the DB check, --no-backup skips backup, --skip-assets skips the asset block;
        // it then reaches the cache:clear step (which fails in tmpDir — fine, we assert it got there).
        $tester = new CommandTester($this->makeCommand($conn));
        $tester->execute(['--force' => true, '--no-backup' => true, '--skip-assets' => true], ['interactive' => false]);

        $out = $tester->getDisplay();
        self::assertStringContainsString('Continuing despite the unreachable database (--force)', $out);
        self::assertStringContainsString('Cache (rebuild before migrations)', $out, 'force must carry execution past pre-flight + backup');
    }

    public function testNoBackupSkipsBackupWithLoudWarning(): void
    {
        $this->writeEnvLocal("DATABASE_URL=mysql://u:p@127.0.0.1:3306/db\n");

        $conn = $this->createStub(Connection::class);
        $conn->method('executeQuery')->willReturn($this->createStub(Result::class)); // reachable DB

        $tester = new CommandTester($this->makeCommand($conn));
        $tester->execute(['--no-backup' => true, '--skip-assets' => true], ['interactive' => false]);

        $out = $tester->getDisplay();
        self::assertStringContainsString('Backup skipped on request (--no-backup)', $out);
        // Proves it didn't abort at backup: it advanced to the cache:clear step after the warning.
        self::assertStringContainsString('Cache (rebuild before migrations)', $out);
    }
}
