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
            ->addOption('no-backup', null, InputOption::VALUE_NONE, 'Preskoči DB backup (svjesno — ispisuje glasno upozorenje)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Nastavi i kad pre-flight nije siguran ili backup nije moguć')
            ->addOption('skip-assets', null, InputOption::VALUE_NONE, 'Preskoči rekompajl asseta');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Tallyst — finalizacija nadogradnje');

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
        $io->section('Cache (rebuild prije migracija)');
        if (!$this->step($io, ['cache:clear'])) {
            $io->error('cache:clear nije uspio — ne nastavljam s migracijama nad starim kontejnerom. Popravi pa ponovi `app:upgrade:finalize` (idempotentno je).');

            return Command::FAILURE;
        }

        // 4. MIGRATE -----------------------------------------------------------------------------
        $io->section('Migracije baze');
        if (!$this->step($io, ['doctrine:migrations:migrate', '--no-interaction'])) {
            $io->error('Migracija nije uspjela. Backup je u var/backups/ — vrati bazu iz njega ako treba. Popravi pa ponovi `app:upgrade:finalize` (idempotentno je).');

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
        $io->section('Pre-flight provjere');

        if (!\extension_loaded('sodium')) {
            $io->error('PHP ekstenzija "sodium" nije učitana — potrebna je za enkripciju postavki.');

            return false;
        }

        $envLocal = is_file($this->projectDir.'/.env.local') ? (string) file_get_contents($this->projectDir.'/.env.local') : '';
        if (!$this->state->envLocalHasDatabaseUrl($envLocal)) {
            $io->error('.env.local nema DATABASE_URL — ovo ne izgleda kao instaliran Tallyst. Pokreni `app:install` prvo.');

            return false;
        }

        // The booted connection reflects the current DATABASE_URL (the code swap doesn't touch it).
        try {
            $this->prober->ping($this->connection);
            $io->writeln('• Baza dostupna.');
        } catch (\Throwable $e) {
            $io->error('Baza nije dostupna: '.$e->getMessage());
            if (!$force) {
                return false;
            }
            $io->warning('Nastavljam unatoč nedostupnoj bazi (--force).');
        }

        // Schema presence is informational — an upgrade with no existing schema is odd, not fatal.
        try {
            if (!$this->state->coreTablesExist($this->connection)) {
                $io->warning('Baza nema Tallyst tablice — nema postojeće sheme za nadogradnju (svjež install? pokreni `app:install`).');
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
        $io->section('Backup baze (prije migracija)');

        if ($noBackup) {
            $io->warning('Backup preskočen na zahtjev (--no-backup). NEMA sigurnosne kopije baze prije migracija.');

            return true;
        }

        if (null === $this->backup->findDumpBinary()) {
            $io->warning('Nije pronađen mysqldump ni mariadb-dump na PATH-u — backup baze NIJE moguć.');

            return $this->continueWithoutBackup($io, $force, $interactive);
        }

        try {
            $this->backup->dump($io);

            return true;
        } catch (BackupException $e) {
            $io->warning('Backup nije uspio: '.$e->getMessage());

            return $this->continueWithoutBackup($io, $force, $interactive);
        }
    }

    private function continueWithoutBackup(SymfonyStyle $io, bool $force, bool $interactive): bool
    {
        if ($force) {
            $io->warning('Nastavljam BEZ backupa (--force).');

            return true;
        }
        if ($interactive) {
            if ($io->confirm('Nastaviti nadogradnju BEZ backupa baze?', false)) {
                return true;
            }
            $io->writeln('Prekinuto — napravi backup ručno pa ponovi.');

            return false;
        }

        $io->error('Backup nije moguć, a nije zadan --no-backup ni --force — prekidam radi sigurnosti.');

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
            $io->writeln('• --skip-assets — preskačem rekompajl asseta.');

            return [];
        }

        $io->section('Asseti (rekompajl)');

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
                'Asseti NISU u potpunosti rekompajlirani ('.implode(', ', $assetFailures).') — admin/front JavaScript možda neće raditi (npr. gumbi koji ništa ne rade).',
                'Pokreni ručno pa hard-refresh u pregledniku:',
                '  php8.5 bin/console importmap:install',
                '  php8.5 bin/console asset-map:compile',
                '  php8.5 bin/console app:theme:assets:install',
            ]);
        }

        $io->success('Nadogradnja je finalizirana.');
        $io->writeln([
            '<comment>Sljedeći koraci (vidljivi — traže ops ovlasti, ne radi ih ova komanda):</comment>',
            '  1) Restartaj messenger worker (radi STARI kod dok ga ne restartaš):',
            '       systemctl --user restart tallyst-messenger',
            '     (user-level systemd unit u ~/.config/systemd/user/ + `loginctl enable-linger` — vidi CLAUDE.md)',
            '  2) Hard-refresh u pregledniku (stari asseti znaju ostati u cacheu).',
            '',
            '<comment>Podsjetnik (backup):</comment>',
            '  DB backup (ako je napravljen) je u var/backups/. `.env.local` (osobito',
            '  SETTINGS_ENCRYPTION_KEY) i public/media/ backupiraj zasebno — gubitak ključa =',
            '  nepovratno mrtva SMTP lozinka.',
        ]);
    }
}
