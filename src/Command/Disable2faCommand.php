<?php

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Escape hatch: clear a user's 2FA (secret + enabled + backup codes) from the CLI. On a
 * self-hosted box this is the always-available recovery if someone loses BOTH their
 * authenticator app and their backup codes — they can then log in with the password alone.
 *
 *   php8.5 bin/console app:user:2fa:disable da@svejedobro.hr
 */
#[AsCommand(name: 'app:user:2fa:disable', description: 'Disable 2FA for a user (recovery escape hatch).')]
class Disable2faCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Login e-mail of the user');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');

        $user = $this->users->findOneByEmail($email);
        if (null === $user) {
            $io->error(sprintf('No user with e-mail "%s".', $email));

            return Command::FAILURE;
        }

        $user->setTotpSecret(null)->setTotpEnabled(false)->setBackupCodes(null);
        $this->em->flush();

        $io->success(sprintf('2FA disabled for "%s" — they can now log in with the password alone.', $email));

        return Command::SUCCESS;
    }
}
