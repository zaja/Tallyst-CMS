<?php

namespace App\Console;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs `php bin/console <args>` as a FRESH-KERNEL subprocess, streaming output. Extracted from
 * InstallCommand so the install AND upgrade flows share one implementation.
 *
 * Why a subprocess: symfony/runtime froze the boot-time .env into the compiled container, so once
 * a command rewrites .env.local (install) — or the code is replaced and the prod container is stale
 * (upgrade) — the in-process kernel is wrong. Each step boots a fresh kernel reading the new state;
 * childEnv() strips the .env-managed keys so the child re-reads them from .env.local rather than
 * inheriting the parent's frozen values.
 */
final class ConsoleStepRunner
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * Build a child-process env: strip the .env-managed keys (so the child re-reads them FRESH
     * from .env.local — a present env var would otherwise win over the file), plus any extras
     * (e.g. an admin password). `false` removes a var from the child env.
     *
     * @param array<string, string> $extra
     *
     * @return array<string, string|false>
     */
    public function childEnv(array $extra = []): array
    {
        return array_merge([
            'DATABASE_URL' => false,
            'APP_SECRET' => false,
            'DEFAULT_URI' => false,
            'ORDER_ADMIN_EMAIL' => false,
            'SETTINGS_ENCRYPTION_KEY' => false,
            // Strip APP_ENV/APP_DEBUG too: Dotenv won't let .env.local override an env var the
            // child inherited from the parent ($_SERVER/$_ENV — and bootEnv even sets APP_DEBUG),
            // so without this the subprocesses would run in the parent's (dev) mode instead of the
            // prod we just wrote to .env.local. Unset → each child recomputes prod + APP_DEBUG=0.
            'APP_ENV' => false,
            'APP_DEBUG' => false,
        ], $extra);
    }

    /**
     * @param array<int, string>          $args
     * @param array<string, string|false> $env
     */
    public function run(SymfonyStyle $io, array $args, array $env, bool $warnOnly = false): bool
    {
        $io->writeln('<info>→ php bin/console '.implode(' ', $args).'</info>');

        $php = self::resolvePhpBinary(
            \PHP_SAPI,
            'php'.\PHP_MAJOR_VERSION.'.'.\PHP_MINOR_VERSION,
            static fn (string $name): ?string => (new ExecutableFinder())->find($name),
            static fn (): string|false => (new PhpExecutableFinder())->find(false),
            \PHP_BINARY,
        );

        $process = new Process(array_merge([$php, 'bin/console'], $args), $this->projectDir, $env);
        $process->setTimeout(600);
        $process->run(static function (string $type, string $buffer) use ($io): void {
            $io->write($buffer);
        });

        if ($process->isSuccessful()) {
            return true;
        }

        if ($warnOnly) {
            $io->warning('Korak nije uspio (nastavljam): php bin/console '.implode(' ', $args));
        }

        return false;
    }

    /**
     * Pick the CLI php binary to run `bin/console` with — SAPI-aware.
     *
     * Under a CLI SAPI (install/upgrade, run from a terminal) `\PHP_BINARY` IS the CLI binary, so
     * use it and keep that path byte-identical. Under a NON-CLI SAPI (php-fpm — the demo admin
     * buttons) `\PHP_BINARY` is the SAPI executable (e.g. `/usr/sbin/php-fpm8.5`) which can't run a
     * script — it just prints its usage and exits 64, so the subprocess "fails". There we resolve
     * the VERSION-MATCHED CLI binary (`php{MAJOR}.{MINOR}`, e.g. `php8.5`) — never a bare `php`,
     * which on this host is a DIFFERENT version (the CLAUDE.md "wrong PHP version" gotcha). The
     * fpm worker already runs the site's PHP version, so the versioned name matches by definition.
     *
     * Pure + injected finders so it's unit-testable without a real fpm process.
     *
     * @param callable(string): ?string       $findVersioned locate a named executable (ExecutableFinder)
     * @param callable(): (string|false)       $findPhp       PhpExecutableFinder fallback
     */
    public static function resolvePhpBinary(
        string $sapi,
        string $versionedName,
        callable $findVersioned,
        callable $findPhp,
        string $phpBinary,
    ): string {
        // 1) CLI: PHP_BINARY is the CLI binary — unchanged for install/upgrade.
        if (\in_array($sapi, ['cli', 'cli-server', 'phpdbg'], true)) {
            return $phpBinary;
        }

        // 2) Non-CLI (fpm/web): the version-matched CLI binary is primary.
        if (($found = $findVersioned($versionedName)) !== null && '' !== $found) {
            return $found;
        }

        // 3) Fallback chain: PhpExecutableFinder → PHP_BINARY (last resort).
        if (($found = $findPhp()) !== false && '' !== $found) {
            return $found;
        }

        return $phpBinary;
    }
}
