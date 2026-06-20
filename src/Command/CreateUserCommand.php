<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Validation;

/**
 * Creates a back-office user. The password is read interactively from a hidden
 * prompt (stdin) so it never lands in the shell history or process list.
 *
 *   php8.5 bin/console app:user:create admin@example.com
 *   php8.5 bin/console app:user:create editor@example.com --role=ROLE_EDITOR
 */
#[AsCommand(name: 'app:user:create', description: 'Create an admin user (password read from a hidden prompt).')]
class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Login e-mail address')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'Role to grant', 'ROLE_ADMIN');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = (string) $input->getArgument('email');
        $role = (string) $input->getOption('role');

        $validator = Validation::createValidator();
        $violations = $validator->validate($email, new Email());
        if (count($violations) > 0) {
            $io->error(sprintf('"%s" is not a valid e-mail address.', $email));

            return Command::INVALID;
        }

        if (null !== $this->users->findOneByEmail($email)) {
            $io->error(sprintf('A user with e-mail "%s" already exists.', $email));

            return Command::FAILURE;
        }

        // Hidden password prompt — never passed as an argument.
        $passwordQuestion = (new Question('Password: '))
            ->setHidden(true)
            ->setHiddenFallback(false)
            ->setValidator(static function (?string $value): string {
                if (null === $value || strlen($value) < 8) {
                    throw new \RuntimeException('Password must be at least 8 characters.');
                }

                return $value;
            });

        $confirmQuestion = (new Question('Repeat password: '))
            ->setHidden(true)
            ->setHiddenFallback(false);

        $password = $io->askQuestion($passwordQuestion);
        $confirm = $io->askQuestion($confirmQuestion);

        if ($password !== $confirm) {
            $io->error('Passwords do not match.');

            return Command::INVALID;
        }

        $user = (new User($email))->setRoles([$role]);
        $user->setPassword($this->hasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf('User "%s" created with role %s.', $email, $role));

        return Command::SUCCESS;
    }
}
