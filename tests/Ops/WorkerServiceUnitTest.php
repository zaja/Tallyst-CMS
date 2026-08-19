<?php

namespace App\Tests\Ops;

use App\Ops\WorkerServiceUnit;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The service file Tallyst writes for the owner, and the line it must never cross.
 *
 * ⚠ WHY IT WRITES THIS AT ALL. The installer used to send the owner to a template in the docs to
 * assemble by hand — while the application already knew the site path, the right PHP binary and the
 * exact queue list. A step somebody has to assemble is a step that gets skipped, and a skipped
 * worker means no e-mail at all: no password resets, no order confirmations.
 *
 * ⚠ AND THE LINE: it writes, it never starts. "Tallyst starts nothing outside itself" is the ops
 * boundary; a file can be read, edited or deleted before it does anything, a started process cannot.
 */
class WorkerServiceUnitTest extends KernelTestCase
{
    private string $home;
    private ?string $realHome = null;

    protected function setUp(): void
    {
        self::bootKernel();
        // ⚠ Captured, not reconstructed. Restoring from $_SERVER assumes it is set; if it ever is
        // not, the fallback silently redirects HOME for every test that runs after this one — the
        // kind of leak that shows up as an unrelated failure in a different file.
        $this->realHome = getenv('HOME') ?: null;
        $this->home = sys_get_temp_dir().'/tallyst-unit-'.uniqid();
        mkdir($this->home, 0o755, true);
        putenv('HOME='.$this->home);
    }

    protected function tearDown(): void
    {
        null === $this->realHome ? putenv('HOME') : putenv('HOME='.$this->realHome);
        $unit = $this->home.'/.config/systemd/user/tallyst-messenger.service';
        if (is_file($unit)) {
            unlink($unit);
        }
        foreach (['/.config/systemd/user', '/.config/systemd', '/.config'] as $dir) {
            if (is_dir($this->home.$dir)) {
                rmdir($this->home.$dir);
            }
        }
        if (is_dir($this->home)) {
            rmdir($this->home);
        }
        parent::tearDown();
    }

    private function unit(): WorkerServiceUnit
    {
        return static::getContainer()->get(WorkerServiceUnit::class);
    }

    /**
     * ⚠ THE QUEUE LIST IS READ, NOT RESTATED — the same source the readiness panel uses. A queue
     * added in a later release lands in every newly written unit without anybody editing a template,
     * which is exactly the drift that made an owner add `scheduler_order_maintenance` by hand.
     */
    public function testTheUnitNamesEveryQueueThisInstallHas(): void
    {
        $execStart = $this->unit()->execStart();

        self::assertStringContainsString('messenger:consume async', $execStart);
        self::assertStringContainsString('scheduler_order_maintenance', $execStart);
        self::assertStringNotContainsString('failed', $execStart, 'the failure store is drained by hand, never consumed');
    }

    /** Absolute paths only — a unit runs with no shell and no working directory of its own. */
    public function testEveryPathInTheUnitIsAbsolute(): void
    {
        $contents = $this->unit()->unitContents();

        self::assertMatchesRegularExpression('#^ExecStart=/#m', $contents);
        self::assertMatchesRegularExpression('#^WorkingDirectory=/#m', $contents);
        self::assertStringContainsString('/bin/console', $contents);
    }

    /**
     * ⚠ A bare `php` is the wrong PHP on hosts with several versions — the same gotcha that governs
     * ConsoleStepRunner. A worker started against the wrong one fails in ways nobody traces back.
     */
    public function testItNeverUsesABarePhp(): void
    {
        self::assertDoesNotMatchRegularExpression('/ExecStart=php /', $this->unit()->unitContents());
    }

    /**
     * ⚠ THE USERNAME IS RESOLVED, NEVER A PLACEHOLDER. `loginctl enable-linger` is the one line an
     * owner would otherwise have to complete themselves, and the one whose purpose is least obvious
     * — so it is also the one most likely to be skipped, and skipping it stops the worker the moment
     * they log out.
     */
    public function testTheUsernameIsReal(): void
    {
        $username = $this->unit()->username();

        self::assertNotSame('', $username);
        self::assertNotSame('YOUR-USER', $username, 'a placeholder here defeats the point of writing the file');
        self::assertDoesNotMatchRegularExpression('/\s/', $username);
    }

    public function testItWritesTheUnitIntoTheOwnersOwnHome(): void
    {
        $unit = $this->unit();
        $path = $unit->write();

        self::assertStringStartsWith($this->home, $path, 'never a system-wide path');
        self::assertFileExists($path);
        self::assertStringContainsString($unit->execStart(), (string) file_get_contents($path));
    }

    /** Writing twice is harmless, and the second time it knows nothing needs doing. */
    public function testAnAlreadyCorrectUnitIsRecognised(): void
    {
        $unit = $this->unit();
        $unit->write();

        self::assertTrue($unit->existingIsCurrent());
    }

    /**
     * ⚠ --update IS SURGICAL ON PURPOSE. An owner may have added Environment= lines, a different
     * memory limit or an OnFailure hook; an upgrade that rewrote the whole file would discard their
     * work without a word.
     */
    public function testUpdateRewritesOnlyTheExecStartLine(): void
    {
        $unit = $this->unit();
        $path = $unit->write();

        $tampered = str_replace(' scheduler_order_maintenance', '', (string) file_get_contents($path));
        file_put_contents($path, $tampered."Environment=\"APP_ENV=prod\"\n");

        $unit->updateExecStart();
        $after = (string) file_get_contents($path);

        self::assertStringContainsString('scheduler_order_maintenance', $after, 'the queue is restored');
        self::assertStringContainsString('Environment="APP_ENV=prod"', $after, "the owner's own line survives");
    }

    /**
     * ⚠ THE BOUNDARY, AND THE ONE ASSERTION THAT MATTERS MOST HERE. The command may write a unit; it
     * must never start one. Anything that shells out — systemctl, loginctl, a Process — turns a file
     * the owner can still inspect into a process running on their machine without their say-so.
     */
    public function testTheCommandStartsNothing(): void
    {
        $root = \dirname(__DIR__, 2);

        foreach (['/src/Command/WorkerInstallCommand.php', '/src/Ops/WorkerServiceUnit.php'] as $file) {
            $code = (string) file_get_contents($root.$file);
            // Comments explain the boundary in words; only the CODE is asserted on.
            $code = preg_replace('#(^\s*\*.*$)|(^\s*//.*$)#m', '', $code) ?? $code;

            foreach (['exec(', 'shell_exec', 'passthru', 'proc_open', 'popen(', 'new Process'] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $code,
                    $file.' must never execute anything — Tallyst writes the unit, the owner starts it.',
                );
            }
        }
    }

    /** The two systemctl commands must still be PRINTED, or the owner is left with a file and no idea. */
    public function testTheStartInstructionsAreShown(): void
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:worker:install'));
        $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertStringContainsString('systemctl --user enable --now tallyst-messenger', $display);
        self::assertStringContainsString('loginctl enable-linger '.$this->unit()->username(), $display);
        self::assertStringContainsString('log out', $display, 'the reason enable-linger matters');
    }

    /** --print changes nothing on disk. */
    public function testPrintWritesNothing(): void
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:worker:install'));
        $tester->execute(['--print' => true]);

        self::assertFileDoesNotExist($this->unit()->unitPath());
        self::assertStringContainsString('[Service]', $tester->getDisplay());
    }
}
