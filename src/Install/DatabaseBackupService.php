<?php

namespace App\Install;

use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Produces a pre-upgrade MySQL/MariaDB dump into var/backups/ — the unavoidable safety net before
 * migrations run (the only irreversible step a git-based upgrade can't undo). The caller decides
 * what a failure means; this service only succeeds honestly or throws BackupException — it NEVER
 * reports a backup it didn't make.
 */
final class DatabaseBackupService
{
    public function __construct(
        #[Autowire('%env(DATABASE_URL)%')]
        private readonly string $databaseUrl,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * Locate a dump binary — mysqldump, then mariadb-dump — or null if neither is on PATH.
     * DETECTS, never assumes: the caller surfaces a missing binary loudly (confirm / --no-backup).
     */
    public function findDumpBinary(): ?string
    {
        $finder = new ExecutableFinder();

        return $finder->find('mysqldump') ?? $finder->find('mariadb-dump');
    }

    /**
     * Connection parts straight from DATABASE_URL via DsnParser — the SAME parser DatabaseProber
     * uses. DsnParser already rawurl-DECODES user/password, so these are usable as-is: pass them
     * THROUGH to mysqldump/MYSQL_PWD, never decode again (double-decoding mangles a password with
     * reserved characters). The DB name is under `dbname` (NOT `path` — that's the sqlite key).
     *
     * @return array{host: string, port: string, user: string, password: string, dbname: string}
     */
    public function parseDsn(): array
    {
        $p = (new DsnParser([
            'mysql' => 'pdo_mysql',
            'mariadb' => 'pdo_mysql',
        ]))->parse($this->databaseUrl);

        return [
            'host' => (string) ($p['host'] ?? '127.0.0.1'),
            'port' => (string) ($p['port'] ?? '3306'),
            'user' => (string) ($p['user'] ?? ''),
            'password' => (string) ($p['password'] ?? ''),
            'dbname' => (string) ($p['dbname'] ?? ''),
        ];
    }

    /**
     * Dump the configured database to var/backups/tallyst-pre-upgrade-<ts>.sql and return the path.
     * Throws BackupException (never returns a path it didn't fully write) on a missing binary or a
     * failed dump.
     */
    public function dump(SymfonyStyle $io): string
    {
        $bin = $this->findDumpBinary();
        if (null === $bin) {
            throw new BackupException('Nije pronađen mysqldump ni mariadb-dump na PATH-u — backup nije moguć.');
        }

        $creds = $this->parseDsn();
        if ('' === $creds['dbname']) {
            throw new BackupException('DATABASE_URL ne sadrži naziv baze — backup nije moguć.');
        }

        $dir = $this->projectDir.'/var/backups';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new BackupException(sprintf('Ne mogu kreirati direktorij za backup: %s', $dir));
        }

        $path = sprintf('%s/tallyst-pre-upgrade-%s.sql', $dir, date('Y-m-d_His'));

        $io->writeln(sprintf('<info>→ %s --single-transaction %s → %s</info>', basename($bin), $creds['dbname'], $path));

        // fromShellCommandline + quoted "${:VAR}" placeholders: Process escapes each via
        // escapeArgument (the regex REQUIRES the surrounding double quotes), so the DB name and the
        // file path can't break out of the shell. The `>` redirect makes mysqldump write the file
        // directly (zero PHP memory). The binary path comes from ExecutableFinder (trusted), and the
        // password travels via the MYSQL_PWD env var — NEVER argv (it would show in `ps`/history).
        $process = Process::fromShellCommandline(
            'exec '.$bin.' --single-transaction --routines --no-tablespaces '
            .'--host="${:DB_HOST}" --port="${:DB_PORT}" --user="${:DB_USER}" "${:DB_NAME}" > "${:DUMP_FILE}"',
            $this->projectDir,
            null,
            null,
            600,
        );
        $process->run(null, [
            'DB_HOST' => $creds['host'],
            'DB_PORT' => $creds['port'],
            'DB_USER' => $creds['user'],
            'DB_NAME' => $creds['dbname'],
            'DUMP_FILE' => $path,
            'MYSQL_PWD' => $creds['password'],
        ]);

        if (!$process->isSuccessful()) {
            @unlink($path); // don't leave a half-written file that looks like a backup
            throw new BackupException(sprintf('mysqldump nije uspio: %s', trim($process->getErrorOutput()) ?: 'nepoznata greška'));
        }

        if (!is_file($path) || filesize($path) < 1) {
            @unlink($path);
            throw new BackupException('Backup datoteka je prazna — dump nije proizveo podatke.');
        }

        $io->writeln(sprintf('• Backup zapisan: %s (%s)', $path, $this->humanSize((int) filesize($path))));

        return $path;
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < \count($units) - 1) {
            $size /= 1024;
            ++$i;
        }

        return sprintf('%.1f %s', $size, $units[$i]);
    }
}
