<?php

namespace App\Command;

use App\Console\ConsoleStepRunner;
use App\Install\BackupException;
use App\Install\DatabaseBackupService;
use App\Install\DatabaseProber;
use App\Install\InstallStateDetector;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Finalises an upgrade: runs every DETERMINISTIC post-code-swap step (DB backup → cache:clear →
 * migrate → asset rebuild → cache:clear) as fresh-kernel subprocesses, then prints the visible
 * follow-ups (worker restart). The risky bit — replacing the code itself — stays OUTSIDE this
 * command (the operator does `git checkout … && composer install` first); this command never
 * overwrites its own running code.
 *
 * ⚠ ORDERING differs from install: install builds from scratch so cache:clear is LAST; an upgrade
 * REPLACES existing code, so under prod (one compiled container) the stale var/cache/prod/Container*
 * still references the OLD code — the first subprocess (migrate) would boot the old mapping. So
 * cache:clear runs BEFORE migrate, rebuilding the container over the new code.
 */
#[AsCommand(name: 'app:upgrade:finalize', description: 'Finalize an upgrade: backup, cache:clear (before migrate), migrate, rebuild assets.')]
class UpgradeFinalizeCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly Connection $connection,
        private readonly InstallStateDetector $state,
        private readonly DatabaseProber $prober,
        private readonly ConsoleStepRunner $steps,
        private readonly DatabaseBackupService $backup,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('no-backup', null, InputOption::VALUE_NONE, 'Skip the DB backup (deliberate — prints a loud warning)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Continue even when pre-flight is uncertain or a backup is impossible')
            ->addOption('skip-assets', null, InputOption::VALUE_NONE, 'Skip the asset rebuild');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Tallyst — upgrade finalization');

        $interactive = $input->isInteractive();
        $force = (bool) $input->getOption('force');
        $noBackup = (bool) $input->getOption('no-backup');
        $skipAssets = (bool) $input->getOption('skip-assets');

        // 1. PRE-FLIGHT --------------------------------------------------------------------------
        if (!$this->preflight($io, $force)) {
            return Command::FAILURE;
        }

        // 2. BACKUP (unavoidable safety net before the only irreversible step — migrations) -------
        if (!$this->runBackup($io, $noBackup, $force, $interactive)) {
            return Command::FAILURE;
        }

        // 3. cache:clear BEFORE migrate (rebuild the container over the NEW code) -----------------
        $io->section('Cache (rebuild before migrations)');
        if (!$this->step($io, ['cache:clear'])) {
            $io->error('cache:clear failed — not continuing with migrations over the old container. Fix it and re-run `app:upgrade:finalize` (it is idempotent).');

            return Command::FAILURE;
        }

        // 4. MIGRATE -----------------------------------------------------------------------------
        $io->section('Database migrations');
        if (!$this->step($io, ['doctrine:migrations:migrate', '--no-interaction'])) {
            $io->error('Migration failed. A backup is in var/backups/ — restore the database from it if needed. Fix it and re-run `app:upgrade:finalize` (it is idempotent).');

            return Command::FAILURE;
        }

        // 5. ASSET REBUILD (non-fatal; upgrade ALWAYS recompiles — new code = new assets) --------
        $assetFailures = $this->runAssetSteps($io, $skipAssets);

        // 6. FINAL cache:clear (warn-only) -------------------------------------------------------
        $this->step($io, ['cache:clear'], true);

        // 7. FINAL MESSAGE -----------------------------------------------------------------------
        $this->finalMessage($io, $assetFailures);

        return Command::SUCCESS;
    }

    private function preflight(SymfonyStyle $io, bool $force): bool
    {
        $io->section('Pre-flight checks');

        if (!\extension_loaded('sodium')) {
            $io->error('The PHP "sodium" extension is not loaded — it is required to encrypt settings.');

            return false;
        }

        $envLocal = is_file($this->projectDir.'/.env.local') ? (string) file_get_contents($this->projectDir.'/.env.local') : '';
        if (!$this->state->envLocalHasDatabaseUrl($envLocal)) {
            $io->error('.env.local has no DATABASE_URL — this does not look like an installed Tallyst. Run `app:install` first.');

            return false;
        }

        // The booted connection reflects the current DATABASE_URL (the code swap doesn't touch it).
        try {
            $this->prober->ping($this->connection);
            $io->writeln('• Database reachable.');
        } catch (\Throwable $e) {
            $io->error('Database is not reachable: '.$e->getMessage());
            if (!$force) {
                return false;
            }
            $io->warning('Continuing despite the unreachable database (--force).');
        }

        // Schema presence is informational — an upgrade with no existing schema is odd, not fatal.
        try {
            if (!$this->state->coreTablesExist($this->connection)) {
                $io->warning('The database has no Tallyst tables — there is no existing schema to upgrade (a fresh install? run `app:install`).');
            }
        } catch (\Throwable) {
            // ping already covered reachability; ignore a schema-probe hiccup here.
        }

        return true;
    }

    /**
     * Make a backup, or HONESTLY refuse to claim one. A missing binary / failed dump is graceful:
     * interactive → confirm; non-interactive → abort unless --no-backup/--force. NEVER fakes success.
     */
    private function runBackup(SymfonyStyle $io, bool $noBackup, bool $force, bool $interactive): bool
    {
        $io->section('Database backup (before migrations)');

        if ($noBackup) {
            $io->warning('Backup skipped on request (--no-backup). There is NO database backup before migrations.');

            return true;
        }

        if (null === $this->backup->findDumpBinary()) {
            $io->warning('Neither mysqldump nor mariadb-dump was found on PATH — a database backup is NOT possible.');

            return $this->continueWithoutBackup($io, $force, $interactive);
        }

        try {
            $this->backup->dump($io);

            return true;
        } catch (BackupException $e) {
            $io->warning('Backup failed: '.$e->getMessage());

            return $this->continueWithoutBackup($io, $force, $interactive);
        }
    }

    private function continueWithoutBackup(SymfonyStyle $io, bool $force, bool $interactive): bool
    {
        if ($force) {
            $io->warning('Continuing WITHOUT a backup (--force).');

            return true;
        }
        if ($interactive) {
            if ($io->confirm('Continue the upgrade WITHOUT a database backup?', false)) {
                return true;
            }
            $io->writeln('Aborted — make a backup manually and try again.');

            return false;
        }

        $io->error('A backup is not possible and neither --no-backup nor --force was given — aborting for safety.');

        return false;
    }

    private function step(SymfonyStyle $io, array $args, bool $warnOnly = false): bool
    {
        return $this->steps->run($io, $args, $this->steps->childEnv(), $warnOnly);
    }

    /**
     * @return array<int, string> failed asset steps (collected, surfaced loudly at the end)
     */
    private function runAssetSteps(SymfonyStyle $io, bool $skipAssets): array
    {
        if ($skipAssets) {
            $io->writeln('• --skip-assets — skipping the asset rebuild.');

            return [];
        }

        $io->section('Assets (rebuild)');

        $assetFailures = [];
        // Upgrade ALWAYS recompiles (no "already exists" skip): new code ships new JS/CSS, and a
        // stale public/assets/ leaves Stimulus controllers dead (admin buttons that do nothing).
        foreach (['importmap:install', 'asset-map:compile', 'app:theme:assets:install'] as $cmd) {
            if (!$this->step($io, [$cmd], true)) {
                $assetFailures[] = $cmd;
            }
        }

        return $assetFailures;
    }

    /**
     * @param array<int, string> $assetFailures
     */
    private function finalMessage(SymfonyStyle $io, array $assetFailures): void
    {
        if ([] !== $assetFailures) {
            // Loud, not buried: a failed compile means dead admin/front JS despite a "successful" upgrade.
            $io->warning([
                'Assets were NOT fully rebuilt ('.implode(', ', $assetFailures).') — admin/front JavaScript may not work (e.g. buttons that do nothing).',
                'Run manually, then hard-refresh your browser:',
                '  php8.5 bin/console importmap:install',
                '  php8.5 bin/console asset-map:compile',
                '  php8.5 bin/console app:theme:assets:install',
            ]);
        }

        $io->success('Upgrade finalized.');
        $io->writeln([
            '<comment>Next steps (visible — they need ops privileges; this command does not do them):</comment>',
            '  1) Restart the messenger worker (it keeps running the OLD code until you do):',
            '       see the "Background worker" section in docs/INSTALL.md',
            '       (systemd, supervisor, or cron — depends on your host)',
            '  2) Hard-refresh your browser (stale assets can linger in the cache).',
            '',
            '<comment>Reminder (backup):</comment>',
            '  The DB backup (if made) is in var/backups/. Back up `.env.local` (especially',
            '  SETTINGS_ENCRYPTION_KEY) and public/media/ separately — losing the key permanently',
            '  kills your stored SMTP password.',
        ]);
    }
}
