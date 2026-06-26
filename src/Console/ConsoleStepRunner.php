<?php

namespace App\Console;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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

        $process = new Process(array_merge([\PHP_BINARY, 'bin/console'], $args), $this->projectDir, $env);
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
}
