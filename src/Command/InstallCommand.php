<?php

namespace App\Command;

use App\Console\ConsoleStepRunner;
use App\Install\DatabaseDsnBuilder;
use App\Install\DatabaseProber;
use App\Install\InstallStateDetector;
use App\Install\EnvLocalWriter;
use App\Settings\EncryptionKeyProvisioner;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Validation;

/**
 * Interactive, Ghost-style first-run installer. On a CLEAN environment (fresh site, empty DB)
 * it gathers DB/admin/site input, VALIDATES the DB connection before writing anything, writes
 * .env.local (DATABASE_URL + APP_SECRET + DEFAULT_URI + ORDER_ADMIN_EMAIL + encryption key),
 * then runs migrations, seeds baseline content and creates the admin user.
 *
 * Architecture note: the kernel froze the BOOT-TIME DATABASE_URL into the compiled container,
 * so once we write a new DATABASE_URL to .env.local the in-process connection is stale. Every
 * DB-mutating step therefore runs as a SUBPROCESS (`php8.5 bin/console …`) that boots a fresh
 * kernel reading the new .env.local. We strip the .env-managed vars from each child's
 * environment (Dotenv treats a present env var as authoritative) so the child re-reads them
 * from the file we just wrote. The admin password is forwarded via a private child env var,
 * never argv.
 */
#[AsCommand(name: 'app:install', description: 'Interactive Tallyst installer (DB, admin, migrations, .env.local).')]
class InstallCommand extends Command
{
    private const PASSWORD_ENV = 'TALLYST_ADMIN_PASSWORD';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly Connection $connection,
        private readonly EncryptionKeyProvisioner $keyProvisioner,
        private readonly EnvLocalWriter $envWriter,
        private readonly DatabaseDsnBuilder $dsnBuilder,
        private readonly DatabaseProber $prober,
        private readonly InstallStateDetector $state,
        private readonly ConsoleStepRunner $steps,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('db-host', null, InputOption::VALUE_REQUIRED, 'Database host')
            ->addOption('db-port', null, InputOption::VALUE_REQUIRED, 'Database port')
            ->addOption('db-name', null, InputOption::VALUE_REQUIRED, 'Database name')
            ->addOption('db-user', null, InputOption::VALUE_REQUIRED, 'Database user')
            ->addOption('db-pass', null, InputOption::VALUE_REQUIRED, 'Database password (CI; prefer interactive)')
            ->addOption('admin-email', null, InputOption::VALUE_REQUIRED, 'Admin login e-mail')
            ->addOption('admin-password', null, InputOption::VALUE_REQUIRED, 'Admin password (CI; prefer the '.self::PASSWORD_ENV.' env var — argv leaks)')
            ->addOption('site-name', null, InputOption::VALUE_REQUIRED, 'Site name')
            ->addOption('site-url', null, InputOption::VALUE_REQUIRED, 'Public absolute URL (DEFAULT_URI)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Proceed even if Tallyst appears already installed')
            ->addOption('skip-assets', null, InputOption::VALUE_NONE, 'Skip the asset compile/publish fallback');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Tallyst — installation');

        $interactive = $input->isInteractive();
        $force = (bool) $input->getOption('force');

        // --- PRE-FLIGHT (no writes) ---------------------------------------------------------
        if (!\extension_loaded('sodium')) {
            $io->error('The PHP "sodium" extension is not loaded — it is required to encrypt settings.');

            return Command::FAILURE;
        }
        foreach ([$this->projectDir, $this->projectDir.'/var'] as $dir) {
            if (!is_dir($dir) || !is_writable($dir)) {
                $io->error(sprintf('Directory is not writable (needed for .env.local / cache): %s', $dir));

                return Command::FAILURE;
            }
        }

        // --- ALREADY-INSTALLED GUARD --------------------------------------------------------
        $envLocal = is_file($this->projectDir.'/.env.local') ? (string) file_get_contents($this->projectDir.'/.env.local') : '';
        if ($this->state->envLocalHasDatabaseUrl($envLocal)) {
            try {
                $this->prober->ping($this->connection); // booted connection reflects this DATABASE_URL
                $installed = $this->state->coreTablesExist($this->connection) || $this->state->anyUserExists($this->connection);
            } catch (\Throwable) {
                $installed = false; // configured DB unreachable → let the operator (re)configure
            }

            if ($installed) {
                if (!$force) {
                    $io->error('Tallyst is already installed (.env.local has DATABASE_URL and the database has data). Refusing to overwrite a live site.');
                    $io->writeln('To reinstall / reconfigure, run with <info>--force</info>.');

                    return Command::FAILURE;
                }
                if ($interactive && !$io->confirm('Tallyst is already installed. Continue in --force mode? (data is NOT deleted, but the configuration is updated)', false)) {
                    $io->writeln('Aborted.');

                    return Command::SUCCESS;
                }
                $io->warning('Continuing in --force mode over an existing installation.');
            }
        }

        // --- GATHER + VALIDATE DB (loop until a connection succeeds) -------------------------
        $io->section('Database');
        $host = (string) ($input->getOption('db-host') ?? '');
        $port = (string) ($input->getOption('db-port') ?? '');
        $name = (string) ($input->getOption('db-name') ?? '');
        $dbUser = (string) ($input->getOption('db-user') ?? '');
        $dbPassOpt = $input->getOption('db-pass');
        $dbPass = null === $dbPassOpt ? null : (string) $dbPassOpt;
        $serverVersion = '';

        while (true) {
            if ($interactive) {
                $host = (string) $io->ask('DB host', '' !== $host ? $host : '127.0.0.1');
                $port = (string) $io->ask('DB port', '' !== $port ? $port : '3306');
                $name = (string) $io->ask('Database name', '' !== $name ? $name : null, $this->requireNonEmpty('Database name is required.'));
                $dbUser = (string) $io->ask('Database user', '' !== $dbUser ? $dbUser : null, $this->requireNonEmpty('Database user is required.'));
                $dbPass = (string) $io->askHidden('Database password (hidden)');
            } else {
                $host = '' !== $host ? $host : '127.0.0.1';
                $port = '' !== $port ? $port : '3306';
                if ('' === $name) {
                    return $this->fail($io, 'Missing --db-name.');
                }
                if ('' === $dbUser) {
                    return $this->fail($io, 'Missing --db-user.');
                }
                if (null === $dbPass) {
                    return $this->fail($io, 'Missing --db-pass.');
                }
            }

            $probeDsn = $this->dsnBuilder->build([
                'host' => $host, 'port' => $port, 'name' => $name, 'user' => $dbUser, 'password' => (string) $dbPass,
            ]);

            try {
                $conn = $this->prober->connect($probeDsn);
                $this->prober->ping($conn);
            } catch (\Throwable $e) {
                $io->error('Database connection failed: '.$e->getMessage());
                if (!$interactive) {
                    return Command::FAILURE;
                }
                $io->note('Check the details and try again.');
                continue;
            }

            // Refuse to install over a NON-empty target schema unless --force.
            if ($this->state->coreTablesExist($conn) && !$force) {
                $io->error('The target database already contains Tallyst tables. Use an empty database or run with --force.');
                if (!$interactive) {
                    return Command::FAILURE;
                }
                continue;
            }

            $serverVersion = $this->prober->detectServerVersion($conn);
            $io->writeln(sprintf('• Connected successfully (%s, serverVersion=%s).', $host, $serverVersion ?: '?'));
            break;
        }

        // --- GATHER + VALIDATE ADMIN / SITE -------------------------------------------------
        $io->section('Administrator and site');

        $adminEmail = (string) ($input->getOption('admin-email') ?? '');
        while (true) {
            if ('' === $adminEmail && $interactive) {
                $adminEmail = (string) $io->ask('Admin e-mail');
            }
            if ('' === $adminEmail) {
                return $this->fail($io, 'Missing --admin-email.', Command::INVALID);
            }
            if (\count(Validation::createValidator()->validate($adminEmail, new Email())) > 0) {
                $io->error(sprintf('"%s" is not a valid e-mail.', $adminEmail));
                if (!$interactive) {
                    return Command::INVALID;
                }
                $adminEmail = '';
                continue;
            }
            break;
        }

        $adminPass = $this->resolveAdminPassword($input, $io, $interactive);
        if (null === $adminPass) {
            return Command::INVALID; // message already emitted
        }

        $siteName = (string) ($input->getOption('site-name') ?? '');
        if ('' === $siteName && $interactive) {
            $siteName = (string) $io->ask('Site name', 'Tallyst');
        }
        if ('' === $siteName) {
            $siteName = 'Tallyst';
        }

        $siteUrl = (string) ($input->getOption('site-url') ?? '');
        while (true) {
            if ('' === $siteUrl && $interactive) {
                $siteUrl = (string) $io->ask('Public site URL (DEFAULT_URI, e.g. https://tallyst.org)');
            }
            if ('' === $siteUrl) {
                return $this->fail($io, 'Missing --site-url.', Command::INVALID);
            }
            $siteUrl = rtrim($siteUrl, '/');
            if (!$this->isAbsoluteHttpUrl($siteUrl)) {
                $io->error('The URL must be an absolute http(s) URL (e.g. https://tallyst.org).');
                if (!$interactive) {
                    return Command::INVALID;
                }
                $siteUrl = '';
                continue;
            }
            break;
        }

        // --- WRITE CONFIG (all validation passed) -------------------------------------------
        $io->section('Writing configuration (.env.local)');
        $finalDsn = $this->dsnBuilder->build([
            'host' => $host, 'port' => $port, 'name' => $name, 'user' => $dbUser,
            'password' => (string) $dbPass, 'serverVersion' => $serverVersion,
        ]);

        $pairs = [
            'DATABASE_URL' => $finalDsn,
            'DEFAULT_URI' => $siteUrl,
            'ORDER_ADMIN_EMAIL' => $adminEmail,
        ];
        if ($this->envWriter->hasNonEmpty('APP_SECRET')) {
            $io->writeln('• APP_SECRET already exists — kept (rotating it would break sessions).');
        } else {
            $pairs['APP_SECRET'] = bin2hex(random_bytes(16));
            $io->writeln('• Generated APP_SECRET.');
        }
        // Default to PROD (safe: neutral error pages, optimised) without asking. Most installs are
        // production; a developer who wants dev gets an instruction in the final message. Only-if-
        // missing, so a re-run/--force never clobbers a developer's deliberate APP_ENV=dev.
        if ($this->envWriter->hasNonEmpty('APP_ENV')) {
            $io->writeln('• APP_ENV already set in .env.local — kept.');
        } else {
            $pairs['APP_ENV'] = 'prod';
            $io->writeln('• Set APP_ENV=prod (safe for production).');
        }
        $this->envWriter->upsert($pairs);
        $io->writeln('• Wrote DATABASE_URL, DEFAULT_URI, ORDER_ADMIN_EMAIL (perms 0600).');

        $io->writeln(null === $this->keyProvisioner->ensure()
            ? '• Encryption key already exists.'
            : '• Generated SETTINGS_ENCRYPTION_KEY.');

        // --- SUBPROCESSES (fresh kernel each; read the new .env.local) -----------------------
        $io->section('Migrations and seed (fresh kernel, new database)');

        if (!$this->steps->run($io, ['doctrine:migrations:migrate', '--no-interaction'], $this->steps->childEnv())) {
            $io->error('.env.local was written, but the schema was NOT created. Fix the database problem (above) and re-run `php8.5 bin/console app:install` — it is idempotent.');

            return Command::FAILURE;
        }

        if (!$this->steps->run(
            $io,
            ['app:install:finalize', '--email='.$adminEmail, '--role=ROLE_ADMIN', '--site-name='.$siteName],
            $this->steps->childEnv([self::PASSWORD_ENV => $adminPass]),
        )) {
            $io->error('Seed / admin creation failed (above). Fix it and re-run `app:install` — it is idempotent.');

            return Command::FAILURE;
        }

        // --- ASSET FALLBACK (git-clone install without the Composer hook) --------------------
        // Asset steps are non-fatal (the rest of the install is fine), BUT a silent failure here
        // leaves the admin/front JS dead (e.g. Stimulus controllers never boot) on a "successful"
        // install — so any failure is collected and surfaced PROMINENTLY in the final message with
        // the exact recompile command, never just a buried mid-flow warning.
        $assetFailures = [];
        if ($input->getOption('skip-assets')) {
            $io->writeln('• --skip-assets — skipping the asset step.');
        } else {
            $io->section('Assets (fallback)');
            if (!$this->steps->run($io, ['importmap:install'], $this->steps->childEnv(), true)) {
                $assetFailures[] = 'importmap:install';
            }

            if ($force || !is_file($this->projectDir.'/public/assets/manifest.json')) {
                if (!$this->steps->run($io, ['asset-map:compile'], $this->steps->childEnv(), true)) {
                    $assetFailures[] = 'asset-map:compile';
                }
            } else {
                $io->writeln('• Assets already compiled — skipping.');
            }

            if ($force || !is_dir($this->projectDir.'/public/themes/default')) {
                if (!$this->steps->run($io, ['app:theme:assets:install'], $this->steps->childEnv(), true)) {
                    $assetFailures[] = 'app:theme:assets:install';
                }
            } else {
                $io->writeln('• Theme assets already published — skipping.');
            }
        }

        $this->steps->run($io, ['cache:clear'], $this->steps->childEnv(), true);

        // --- FINAL MESSAGE ------------------------------------------------------------------
        if ([] !== $assetFailures) {
            // Loud, not buried: a failed compile means dead admin/front JS despite a "successful" install.
            $io->warning([
                'Assets were NOT fully compiled ('.implode(', ', $assetFailures).') — admin/front JavaScript may not work (e.g. buttons that do nothing).',
                'Run manually, then hard-refresh your browser:',
                '  php8.5 bin/console importmap:install',
                '  php8.5 bin/console asset-map:compile',
                '  php8.5 bin/console app:theme:assets:install',
            ]);
        }

        $io->success('Tallyst is installed.');
        $io->writeln([
            sprintf('Open <info>%s/admin</info> and sign in as <info>%s</info>.', $siteUrl, $adminEmail),
            '',
            '<comment>Next steps:</comment>',
            '  1) Start the background worker so e-mails send (password reset, orders):',
            '       see the "Background worker" section in docs/INSTALL.md',
            '       (systemd, supervisor, or cron — depends on your host)',
            '  2) Enter Stripe/PayPal keys and SMTP in Settings (admin).',
            '  3) Live Stripe/PayPal keys + a webhook endpoint WITHOUT basic-auth',
            '       (/webhook/stripe + /webhook/paypal — else payment succeeds but the order stays "processing").',
            '',
            '<comment>Mode:</comment> installed in PROD mode (neutral errors, optimised) — recommended.',
            '  For development mode (detailed errors/debug) set APP_ENV=dev in .env.local, then cache:clear.',
        ]);

        return Command::SUCCESS;
    }

    /**
     * Resolve the admin password: --admin-password (CI), then the TALLYST_ADMIN_PASSWORD env
     * (preferred for CI — argv leaks), else a hidden interactive prompt with confirmation.
     * Returns null (after emitting an error) when it cannot be resolved.
     */
    private function resolveAdminPassword(InputInterface $input, SymfonyStyle $io, bool $interactive): ?string
    {
        $opt = $input->getOption('admin-password');
        $given = null !== $opt ? (string) $opt : ($this->envPassword() ?? null);

        if (null !== $given) {
            if (\strlen($given) < 8) {
                $io->error('The admin password must be at least 8 characters.');

                return null;
            }

            return $given;
        }

        if (!$interactive) {
            $io->error(sprintf('Missing admin password (--admin-password or the %s env var).', self::PASSWORD_ENV));

            return null;
        }

        while (true) {
            $p1 = (string) $io->askHidden('Admin password (hidden, min 8)');
            if (\strlen($p1) < 8) {
                $io->error('The password must be at least 8 characters.');
                continue;
            }
            $p2 = (string) $io->askHidden('Repeat the password');
            if ($p1 !== $p2) {
                $io->error('The passwords do not match.');
                continue;
            }

            return $p1;
        }
    }

    private function envPassword(): ?string
    {
        $v = getenv(self::PASSWORD_ENV);

        return false !== $v && '' !== $v ? $v : null;
    }

    private function isAbsoluteHttpUrl(string $url): bool
    {
        if (!filter_var($url, \FILTER_VALIDATE_URL)) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, \PHP_URL_SCHEME));

        return \in_array($scheme, ['http', 'https'], true) && null !== parse_url($url, \PHP_URL_HOST);
    }

    private function requireNonEmpty(string $message): \Closure
    {
        return static function (?string $value) use ($message): string {
            if (null === $value || '' === trim($value)) {
                throw new \RuntimeException($message);
            }

            return $value;
        };
    }

    private function fail(SymfonyStyle $io, string $message, int $code = Command::FAILURE): int
    {
        $io->error($message);

        return $code;
    }
}
