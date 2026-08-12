<?php

namespace App\Command;

use App\Repository\MemberLoginRequestRepository;
use App\Repository\MemberSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Clears out sign-in links that have expired and sign-ins nobody has used for months.
 *
 * ⚠ WHY THIS EXISTS. Both repositories already had a deleteExpired() and NOTHING CALLED EITHER —
 * code that looks like housekeeping while the tables grow forever. An expired link stops working on
 * its own, so nothing was broken; the rows just accumulated, and a single flood of refused requests
 * could leave tens of thousands of them behind permanently. Same shape of mistake as the theme guard
 * that sat unreferenced for months with a green unit test over logic nothing executed.
 *
 * ⚠ IT DELETES BY DEFAULT, unlike app:media:cache:clean, and that difference is deliberate. That
 * command GUESSES which files are orphans by comparing a directory against a database, so it must
 * assume its inputs may be wrong and refuse; a wrong guess destroys a live thumbnail. This one
 * deletes rows whose own timestamp has passed — a comparison of two dates, with nothing inferred and
 * nothing at risk. Making it dry-run by default would mean owners schedule it, see "would delete",
 * and never learn it did nothing. --dry-run is still there for looking first.
 *
 * ⚠ SIGN-INS ARE THE PART THAT NEEDS CARE. A row here IS somebody's sign-in, so the cutoff must
 * match the one the firewall enforces (90 days from LAST USE, security.yaml `remember_me.lifetime`).
 * Prune sooner and you sign people out early, for no reason they can see and with no way to tell
 * they were not simply logged out at random. Expired links are the harmless half.
 */
#[AsCommand(
    name: 'app:member:prune',
    description: 'Delete expired sign-in links and sign-ins unused for 90 days. Safe to run on a schedule.',
)]
class MemberPruneCommand extends Command
{
    /**
     * ⚠ Must match `remember_me.lifetime` on the member firewall in config/packages/security.yaml
     * (7776000 seconds). Changing one without the other either signs members out early or keeps
     * rows for sign-ins the firewall already refuses.
     */
    public const int SESSION_DAYS = 90;

    public function __construct(
        private readonly MemberLoginRequestRepository $requests,
        private readonly MemberSessionRepository $sessions,
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.environment%')]
        private readonly string $appEnv,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be deleted, and delete nothing.')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Days a sign-in may go unused before it is dropped.', (string) self::SESSION_DAYS);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $days = filter_var($input->getOption('days'), \FILTER_VALIDATE_INT);
        if (false === $days || $days < 1) {
            $io->error('--days must be a whole number of days, 1 or more.');

            return Command::INVALID;
        }

        // Printed before anything happens, so an operator running this by hand can see at once
        // which database is about to be touched — the same courtesy as app:media:cache:clean.
        $io->writeln(\sprintf(
            'Database: <info>%s</info>   Environment: <info>%s</info>',
            $this->em->getConnection()->getDatabase() ?? '(unknown)',
            $this->appEnv,
        ));

        $now = new \DateTimeImmutable();
        $cutoff = $now->modify(\sprintf('-%d days', $days));

        if ($dryRun) {
            $links = $this->requests->countExpired($now);
            $sessions = $this->sessions->countExpired($cutoff);
        } else {
            $links = $this->requests->deleteExpired($now);
            $sessions = $this->sessions->deleteExpired($cutoff);
        }

        $io->writeln('');
        $io->writeln(\sprintf('Expired sign-in links ........ %d', $links));
        $io->writeln(\sprintf('Sign-ins unused since %s ... %d', $cutoff->format('Y-m-d'), $sessions));
        $io->writeln('');

        if ($dryRun) {
            $io->note('Dry run — nothing was deleted. Run without --dry-run to remove these.');

            return Command::SUCCESS;
        }

        $io->success(\sprintf('Removed %d expired sign-in link(s) and %d stale sign-in(s).', $links, $sessions));

        return Command::SUCCESS;
    }
}
