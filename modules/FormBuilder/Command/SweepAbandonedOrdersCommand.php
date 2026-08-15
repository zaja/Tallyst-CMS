<?php

namespace Tallyst\FormBuilder\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tallyst\FormBuilder\Service\AbandonedOrderSweeper;

/**
 * Runs the unfinished-checkout deadline by hand.
 *
 * ⚠ THE SITE DOES NOT DEPEND ON ANYBODY RUNNING THIS. The sweep is scheduled on the Messenger worker
 * that every install already needs for e-mail, so it runs on its own. This command exists for the
 * cases a schedule cannot serve: an owner who wants to see what would happen, one clearing a backlog
 * immediately after upgrading, or somebody debugging.
 *
 * That choice was deliberate. The obvious alternative — telling owners to add a cron entry — has a
 * measured failure mode in this project: app:member:prune shipped exactly that way, and an owner who
 * never adds the entry gets no error, no warning, and no cleanup, silently and for ever. The worker
 * is already mandatory and already documented, so hanging the sweep on it removes a step that could
 * be skipped rather than adding one.
 */
#[AsCommand(
    name: 'app:order:sweep-abandoned',
    description: 'Close checkouts that were never completed. Runs automatically on the worker; this is the manual path.',
)]
class SweepAbandonedOrdersCommand extends Command
{
    public function __construct(private readonly AbandonedOrderSweeper $sweeper)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $activatedAt = $this->sweeper->activatedAt();
        $io->writeln(\sprintf(
            'Watching for unfinished checkouts since: <info>%s</info>',
            $activatedAt?->format('Y-m-d H:i') ?? '(not recorded — nobody will be e-mailed)',
        ));

        $result = $this->sweeper->sweep();

        $io->writeln('');
        $io->writeln(\sprintf('Checkouts closed ............. %d', $result['closed']));
        // Anything abandoned before this site started watching is closed in silence, whatever its
        // age — an upgrade must never write to customers who left before the shop could notice.
        $io->writeln(\sprintf('Of those, eligible to notify . %d', $result['notifiable']));
        $io->writeln('');

        $io->success(\sprintf('Closed %d unfinished checkout(s).', $result['closed']));

        return Command::SUCCESS;
    }
}
