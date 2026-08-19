<?php

namespace App\Ops;

use App\Messenger\ConsumableTransports;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Builds the systemd unit that runs this site's messenger worker, with every path and queue name
 * already filled in.
 *
 * ⚠ WHY THIS EXISTS. Until now the installer told the owner to go and write this file themselves,
 * from a template in docs/INSTALL.md — while the application already knew every single value that
 * belongs in it: where the site is, which PHP binary to use, and which queues a worker must consume.
 * Asking somebody to assemble by hand what we can hand them finished is how the worker step gets
 * skipped, and a skipped worker means no e-mail at all: no password resets, no order confirmations.
 *
 * ⚠ IT WRITES, IT NEVER STARTS. The ops boundary is "Tallyst starts nothing outside itself", not
 * "Tallyst writes no files" — see CLAUDE.md. A unit file the owner can read, edit or delete before
 * anything happens is not the same as a process started on their behalf, so the two `systemctl`
 * commands are PRINTED and the owner runs them knowingly.
 */
final readonly class WorkerServiceUnit
{
    public const string SERVICE_NAME = 'tallyst-messenger';

    public function __construct(
        private ConsumableTransports $transports,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    /** Where the unit belongs, in the owner's own home — never a system-wide path. */
    public function unitPath(): string
    {
        return $this->home().'/.config/systemd/user/'.self::SERVICE_NAME.'.service';
    }

    /**
     * ⚠ Whether user systemd is available AT ALL. Shared hosting frequently has none, and writing a
     * unit file there would leave a file nothing can ever run — worse than saying so plainly.
     */
    public function systemdAvailable(): bool
    {
        return \function_exists('posix_geteuid') && is_dir('/run/user/'.posix_geteuid().'/systemd');
    }

    /**
     * The account the worker runs as — needed verbatim for `loginctl enable-linger`.
     *
     * ⚠ It is resolved, never left as a placeholder. That command is the one line in the whole block
     * an owner would otherwise have to fill in themselves, and it is also the one they are most
     * likely to skip; a copy-paste that silently does nothing is the worst of both.
     */
    public function username(): string
    {
        if (\function_exists('posix_geteuid') && \function_exists('posix_getpwuid')) {
            $entry = posix_getpwuid(posix_geteuid());
            if (\is_array($entry) && \is_string($entry['name'] ?? null) && '' !== $entry['name']) {
                return $entry['name'];
            }
        }

        $env = getenv('USER');

        return \is_string($env) && '' !== $env ? $env : 'YOUR-USER';
    }

    /**
     * The command a worker must run on THIS install: every queue this application declares, read
     * from the container rather than restated, so a queue added in a later release lands here
     * without anybody editing a template.
     */
    public function consumeCommand(): string
    {
        return 'messenger:consume '.implode(' ', $this->transports->expected());
    }

    /** The full ExecStart line — absolute paths, so it works whatever the working directory is. */
    public function execStart(): string
    {
        return \sprintf(
            '%s %s/bin/console %s --time-limit=3600 --memory-limit=128M',
            $this->phpBinary(),
            $this->projectDir,
            $this->consumeCommand(),
        );
    }

    public function unitContents(): string
    {
        return <<<UNIT
            [Unit]
            Description=Tallyst CMS messenger worker
            After=network.target

            [Service]
            Type=simple
            WorkingDirectory={$this->projectDir}
            ExecStart={$this->execStart()}
            # Graceful: systemd sends SIGTERM, the worker finishes the message in hand, then exits.
            Restart=always
            RestartSec=5
            SyslogIdentifier={$this->serviceName()}

            [Install]
            WantedBy=default.target

            UNIT;
    }

    public function serviceName(): string
    {
        return self::SERVICE_NAME;
    }

    /** Does a unit already exist, and does its ExecStart still match what this release needs? */
    public function existingIsCurrent(): bool
    {
        $path = $this->unitPath();
        if (!is_file($path)) {
            return false;
        }

        $existing = (string) file_get_contents($path);

        return str_contains($existing, $this->execStart());
    }

    public function exists(): bool
    {
        return is_file($this->unitPath());
    }

    /**
     * Writes the unit. Returns the path written.
     *
     * ⚠ Nothing is started here, by design — see the class docblock.
     */
    public function write(): string
    {
        $path = $this->unitPath();
        $dir = \dirname($path);

        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Could not create "%s".', $dir));
        }

        if (false === @file_put_contents($path, $this->unitContents())) {
            throw new \RuntimeException(\sprintf('Could not write "%s".', $path));
        }

        return $path;
    }

    /**
     * Rewrites ONLY the ExecStart line of an existing unit.
     *
     * ⚠ Deliberately surgical: an owner may have added their own Environment= lines, a different
     * memory limit, or an OnFailure hook, and an upgrade that overwrote the whole file would throw
     * that away without saying so.
     */
    public function updateExecStart(): string
    {
        $path = $this->unitPath();
        if (!is_file($path)) {
            return $this->write();
        }

        $existing = (string) file_get_contents($path);
        $rewritten = preg_replace('/^ExecStart=.*$/m', 'ExecStart='.$this->execStart(), $existing, 1);

        if (null === $rewritten || $rewritten === $existing && !str_contains($existing, 'ExecStart=')) {
            throw new \RuntimeException(\sprintf('Could not find an ExecStart line in "%s".', $path));
        }

        if (false === @file_put_contents($path, $rewritten)) {
            throw new \RuntimeException(\sprintf('Could not write "%s".', $path));
        }

        return $path;
    }

    /** The cron line for a host with no user systemd — complete, paths and all. */
    public function cronLine(): string
    {
        return \sprintf(
            '* * * * * cd %s && %s bin/console %s --time-limit=60 --limit=10 >/dev/null 2>&1',
            $this->projectDir,
            $this->phpBinary(),
            $this->consumeCommand(),
        );
    }

    private function home(): string
    {
        $home = getenv('HOME');
        if (\is_string($home) && '' !== $home) {
            return rtrim($home, '/');
        }

        if (\function_exists('posix_geteuid') && \function_exists('posix_getpwuid')) {
            $entry = posix_getpwuid(posix_geteuid());
            if (\is_array($entry) && \is_string($entry['dir'] ?? null) && '' !== $entry['dir']) {
                return rtrim($entry['dir'], '/');
            }
        }

        return '/home/'.$this->username();
    }

    /**
     * ⚠ The VERSION-MATCHED CLI binary, never a bare `php` — on a host with several PHP versions a
     * bare `php` is routinely the wrong one, and the worker would then run against a PHP the site
     * does not support. Same rule ConsoleStepRunner already follows.
     */
    private function phpBinary(): string
    {
        $finder = new PhpExecutableFinder();
        $found = $finder->find(false);

        return \is_string($found) && '' !== $found ? $found : \PHP_BINARY;
    }
}
