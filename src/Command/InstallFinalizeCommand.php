<?php

namespace App\Command;

use App\Entity\User;
use App\Install\BaselineSeeder;
use App\Repository\UserRepository;
use App\Settings\SettingsManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Validation;

/**
 * INTERNAL finalize step for the app:install wizard — NOT meant to be run by hand (hidden).
 *
 * It runs in a FRESH kernel (a subprocess the wizard spawns) so it reads the .env.local the
 * wizard just wrote — i.e. the NEW DATABASE_URL — which an in-process step could not (the
 * parent kernel froze the boot-time DATABASE_URL). It seeds baseline content, sets the site
 * name, and creates the admin user. The admin password is read from the TALLYST_ADMIN_PASSWORD
 * env var (never argv → never visible in `ps`/shell history). Every step is idempotent.
 */
#[AsCommand(name: 'app:install:finalize', description: 'Internal: finalize install (seed + admin user).', hidden: true)]
class InstallFinalizeCommand extends Command
{
    private const PASSWORD_ENV = 'TALLYST_ADMIN_PASSWORD';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly BaselineSeeder $seeder,
        private readonly SettingsManager $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Admin login e-mail')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'Admin role', 'ROLE_ADMIN')
            ->addOption('site-name', null, InputOption::VALUE_REQUIRED, 'Site name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = (string) $input->getOption('email');
        $role = (string) $input->getOption('role');
        $siteName = (string) $input->getOption('site-name');
        $password = (string) (getenv(self::PASSWORD_ENV) ?: ($_SERVER[self::PASSWORD_ENV] ?? ''));

        // Defensive re-validation: the wizard already validated, but keep finalize safe if run
        // standalone (e.g. CI). Never trust the inputs blindly.
        if ('' === $email || \count(Validation::createValidator()->validate($email, new Email())) > 0) {
            $io->error('Invalid or missing --email.');

            return Command::INVALID;
        }
        if (!\in_array($role, ['ROLE_ADMIN', 'ROLE_EDITOR'], true)) {
            $io->error(sprintf('Invalid role "%s". Use ROLE_ADMIN or ROLE_EDITOR.', $role));

            return Command::INVALID;
        }
        if (\strlen($password) < 8) {
            $io->error(sprintf('%s env var missing or shorter than 8 characters.', self::PASSWORD_ENV));

            return Command::INVALID;
        }

        // 1) Baseline content (theme/home/post/menu) — idempotent.
        $this->seeder->seed($io);

        // 2) Site name (optional) — a plain, unencrypted General setting.
        if ('' !== $siteName) {
            $this->settings->set('site_name', $siteName);
            $io->writeln('• Set site name.');
        }

        // 3) Admin user — idempotent (skip if the e-mail already exists).
        if (null !== $this->users->findOneByEmail($email)) {
            $io->writeln(sprintf('• Admin user "%s" already exists — skipped.', $email));
        } else {
            $user = (new User($email))->setRoles([$role]);
            $user->setPassword($this->hasher->hashPassword($user, $password));
            $this->em->persist($user);
            $this->em->flush();
            $io->writeln(sprintf('• Created admin user "%s" (%s).', $email, $role));
        }

        $io->success('Finalize complete.');

        return Command::SUCCESS;
    }
}
