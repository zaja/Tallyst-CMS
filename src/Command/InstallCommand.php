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
        $io->title('Tallyst — instalacija');

        $interactive = $input->isInteractive();
        $force = (bool) $input->getOption('force');

        // --- PRE-FLIGHT (no writes) ---------------------------------------------------------
        if (!\extension_loaded('sodium')) {
            $io->error('PHP ekstenzija "sodium" nije učitana — potrebna je za enkripciju postavki.');

            return Command::FAILURE;
        }
        foreach ([$this->projectDir, $this->projectDir.'/var'] as $dir) {
            if (!is_dir($dir) || !is_writable($dir)) {
                $io->error(sprintf('Direktorij nije zapisiv (potrebno za .env.local / cache): %s', $dir));

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
                    $io->error('Tallyst je već instaliran (.env.local ima DATABASE_URL i baza sadrži podatke). Odbijam prebrisati živi site.');
                    $io->writeln('Za reinstalaciju / rekonfiguraciju pokreni s <info>--force</info>.');

                    return Command::FAILURE;
                }
                if ($interactive && !$io->confirm('Tallyst je već instaliran. Nastaviti u --force modu? (podaci se NE brišu, ali konfiguracija se ažurira)', false)) {
                    $io->writeln('Prekinuto.');

                    return Command::SUCCESS;
                }
                $io->warning('Nastavljam u --force modu preko postojeće instalacije.');
            }
        }

        // --- GATHER + VALIDATE DB (loop until a connection succeeds) -------------------------
        $io->section('Baza podataka');
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
                $name = (string) $io->ask('Naziv baze', '' !== $name ? $name : null, $this->requireNonEmpty('Naziv baze je obavezan.'));
                $dbUser = (string) $io->ask('DB korisnik', '' !== $dbUser ? $dbUser : null, $this->requireNonEmpty('DB korisnik je obavezan.'));
                $dbPass = (string) $io->askHidden('DB lozinka (skriveno)');
            } else {
                $host = '' !== $host ? $host : '127.0.0.1';
                $port = '' !== $port ? $port : '3306';
                if ('' === $name) {
                    return $this->fail($io, 'Nedostaje --db-name.');
                }
                if ('' === $dbUser) {
                    return $this->fail($io, 'Nedostaje --db-user.');
                }
                if (null === $dbPass) {
                    return $this->fail($io, 'Nedostaje --db-pass.');
                }
            }

            $probeDsn = $this->dsnBuilder->build([
                'host' => $host, 'port' => $port, 'name' => $name, 'user' => $dbUser, 'password' => (string) $dbPass,
            ]);

            try {
                $conn = $this->prober->connect($probeDsn);
                $this->prober->ping($conn);
            } catch (\Throwable $e) {
                $io->error('Spajanje na bazu nije uspjelo: '.$e->getMessage());
                if (!$interactive) {
                    return Command::FAILURE;
                }
                $io->note('Provjeri podatke i pokušaj ponovno.');
                continue;
            }

            // Refuse to install over a NON-empty target schema unless --force.
            if ($this->state->coreTablesExist($conn) && !$force) {
                $io->error('Ciljna baza već sadrži Tallyst tablice. Koristi praznu bazu ili pokreni s --force.');
                if (!$interactive) {
                    return Command::FAILURE;
                }
                continue;
            }

            $serverVersion = $this->prober->detectServerVersion($conn);
            $io->writeln(sprintf('• Spajanje uspješno (%s, serverVersion=%s).', $host, $serverVersion ?: '?'));
            break;
        }

        // --- GATHER + VALIDATE ADMIN / SITE -------------------------------------------------
        $io->section('Administrator i sajt');

        $adminEmail = (string) ($input->getOption('admin-email') ?? '');
        while (true) {
            if ('' === $adminEmail && $interactive) {
                $adminEmail = (string) $io->ask('Admin e-mail');
            }
            if ('' === $adminEmail) {
                return $this->fail($io, 'Nedostaje --admin-email.', Command::INVALID);
            }
            if (\count(Validation::createValidator()->validate($adminEmail, new Email())) > 0) {
                $io->error(sprintf('"%s" nije ispravan e-mail.', $adminEmail));
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
            $siteName = (string) $io->ask('Naziv sajta', 'Tallyst');
        }
        if ('' === $siteName) {
            $siteName = 'Tallyst';
        }

        $siteUrl = (string) ($input->getOption('site-url') ?? '');
        while (true) {
            if ('' === $siteUrl && $interactive) {
                $siteUrl = (string) $io->ask('Javni URL sajta (DEFAULT_URI, npr. https://tallyst.org)');
            }
            if ('' === $siteUrl) {
                return $this->fail($io, 'Nedostaje --site-url.', Command::INVALID);
            }
            $siteUrl = rtrim($siteUrl, '/');
            if (!$this->isAbsoluteHttpUrl($siteUrl)) {
                $io->error('URL mora biti apsolutan http(s) URL (npr. https://tallyst.org).');
                if (!$interactive) {
                    return Command::INVALID;
                }
                $siteUrl = '';
                continue;
            }
            break;
        }

        // --- WRITE CONFIG (all validation passed) -------------------------------------------
        $io->section('Zapisujem konfiguraciju (.env.local)');
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
            $io->writeln('• APP_SECRET već postoji — zadržan (rotacija bi srušila sesije).');
        } else {
            $pairs['APP_SECRET'] = bin2hex(random_bytes(16));
            $io->writeln('• Generiran APP_SECRET.');
        }
        // Default to PROD (safe: neutral error pages, optimised) without asking. Most installs are
        // production; a developer who wants dev gets an instruction in the final message. Only-if-
        // missing, so a re-run/--force never clobbers a developer's deliberate APP_ENV=dev.
        if ($this->envWriter->hasNonEmpty('APP_ENV')) {
            $io->writeln('• APP_ENV već postavljen u .env.local — zadržan.');
        } else {
            $pairs['APP_ENV'] = 'prod';
            $io->writeln('• Postavljen APP_ENV=prod (sigurno za produkciju).');
        }
        $this->envWriter->upsert($pairs);
        $io->writeln('• Zapisani DATABASE_URL, DEFAULT_URI, ORDER_ADMIN_EMAIL (perms 0600).');

        $io->writeln(null === $this->keyProvisioner->ensure()
            ? '• Enkripcijski ključ već postoji.'
            : '• Generiran SETTINGS_ENCRYPTION_KEY.');

        // --- SUBPROCESSES (fresh kernel each; read the new .env.local) -----------------------
        $io->section('Migracije i seed (svjež kernel, nova baza)');

        if (!$this->steps->run($io, ['doctrine:migrations:migrate', '--no-interaction'], $this->steps->childEnv())) {
            $io->error('.env.local je zapisan, ali shema NIJE kreirana. Popravi problem s bazom (gore) i ponovno pokreni `php8.5 bin/console app:install` — idempotentno je.');

            return Command::FAILURE;
        }

        if (!$this->steps->run(
            $io,
            ['app:install:finalize', '--email='.$adminEmail, '--role=ROLE_ADMIN', '--site-name='.$siteName],
            $this->steps->childEnv([self::PASSWORD_ENV => $adminPass]),
        )) {
            $io->error('Seed / izrada admina nije uspjela (gore). Popravi i ponovno pokreni `app:install` — idempotentno je.');

            return Command::FAILURE;
        }

        // --- ASSET FALLBACK (git-clone install without the Composer hook) --------------------
        // Asset steps are non-fatal (the rest of the install is fine), BUT a silent failure here
        // leaves the admin/front JS dead (e.g. Stimulus controllers never boot) on a "successful"
        // install — so any failure is collected and surfaced PROMINENTLY in the final message with
        // the exact recompile command, never just a buried mid-flow warning.
        $assetFailures = [];
        if ($input->getOption('skip-assets')) {
            $io->writeln('• --skip-assets — preskačem asset korak.');
        } else {
            $io->section('Asseti (fallback)');
            if (!$this->steps->run($io, ['importmap:install'], $this->steps->childEnv(), true)) {
                $assetFailures[] = 'importmap:install';
            }

            if ($force || !is_file($this->projectDir.'/public/assets/manifest.json')) {
                if (!$this->steps->run($io, ['asset-map:compile'], $this->steps->childEnv(), true)) {
                    $assetFailures[] = 'asset-map:compile';
                }
            } else {
                $io->writeln('• Asseti već kompajlirani — preskačem.');
            }

            if ($force || !is_dir($this->projectDir.'/public/themes/default')) {
                if (!$this->steps->run($io, ['app:theme:assets:install'], $this->steps->childEnv(), true)) {
                    $assetFailures[] = 'app:theme:assets:install';
                }
            } else {
                $io->writeln('• Theme asseti već objavljeni — preskačem.');
            }
        }

        $this->steps->run($io, ['cache:clear'], $this->steps->childEnv(), true);

        // --- FINAL MESSAGE ------------------------------------------------------------------
        if ([] !== $assetFailures) {
            // Loud, not buried: a failed compile means dead admin/front JS despite a "successful" install.
            $io->warning([
                'Asseti NISU u potpunosti kompajlirani ('.implode(', ', $assetFailures).') — admin/front JavaScript možda neće raditi (npr. gumbi koji ništa ne rade).',
                'Pokreni ručno pa hard-refresh u pregledniku:',
                '  php8.5 bin/console importmap:install',
                '  php8.5 bin/console asset-map:compile',
                '  php8.5 bin/console app:theme:assets:install',
            ]);
        }

        $io->success('Tallyst je instaliran.');
        $io->writeln([
            sprintf('Otvori <info>%s/admin</info> i prijavi se kao <info>%s</info>.', $siteUrl, $adminEmail),
            '',
            '<comment>Sljedeći koraci:</comment>',
            '  1) Pokreni messenger worker (mailovi reset/narudžbe idu kroz njega):',
            '       systemctl --user restart tallyst-messenger',
            '     (user-level systemd unit u ~/.config/systemd/user/ + `loginctl enable-linger` — vidi CLAUDE.md)',
            '  2) Unesi Stripe/PayPal ključeve i SMTP u Postavke (admin).',
            '  3) Live Stripe/PayPal ključevi + webhook endpoint BEZ basic-auth',
            '       (/webhook/stripe + /webhook/paypal — inače plaćanje uspije ali narudžba ostane "U obradi").',
            '',
            '<comment>Mod:</comment> instalacija je u PROD modu (neutralne greške, optimizirano) — preporučeno.',
            '  Za razvojni mod (detaljne greške/debug) postavi APP_ENV=dev u .env.local, pa cache:clear.',
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
                $io->error('Admin lozinka mora imati barem 8 znakova.');

                return null;
            }

            return $given;
        }

        if (!$interactive) {
            $io->error(sprintf('Nedostaje admin lozinka (--admin-password ili %s env var).', self::PASSWORD_ENV));

            return null;
        }

        while (true) {
            $p1 = (string) $io->askHidden('Admin lozinka (skriveno, min 8)');
            if (\strlen($p1) < 8) {
                $io->error('Lozinka mora imati barem 8 znakova.');
                continue;
            }
            $p2 = (string) $io->askHidden('Ponovi lozinku');
            if ($p1 !== $p2) {
                $io->error('Lozinke se ne podudaraju.');
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
