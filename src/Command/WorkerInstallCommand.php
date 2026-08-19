<?php

namespace App\Command;

use App\Ops\WorkerServiceUnit;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes the systemd unit for the background worker, with every path and queue already filled in.
 *
 * ⚠ IT WRITES THE FILE AND STOPS THERE. The two `systemctl` commands are printed for the owner to
 * run; nothing is executed on their behalf. That is the ops boundary in CLAUDE.md — "Tallyst starts
 * nothing outside itself" — and the reason it sits at execution rather than at writing is that a
 * file can be read, edited or deleted before it does anything, while a started process cannot.
 */
#[AsCommand(
    name: 'app:worker:install',
    description: 'Write the background-worker service file for this install (does not start it).',
)]
class WorkerInstallCommand extends Command
{
    public function __construct(private readonly WorkerServiceUnit $unit)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('update', null, InputOption::VALUE_NONE, 'Rewrite only the ExecStart line of an existing service file.')
            ->addOption('print', null, InputOption::VALUE_NONE, 'Show what would be written, and write nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ((bool) $input->getOption('print')) {
            $io->writeln($this->unit->unitContents());

            return Command::SUCCESS;
        }

        // ⚠ No systemd, no file. Writing a unit on a host that can never run one leaves the owner
        // with something that looks done and is not.
        if (!$this->unit->systemdAvailable()) {
            $io->warning('This host has no user systemd, so no service file was written.');
            $io->writeln('Run the worker from cron instead — this line is complete, paths and all:');
            $io->writeln('');
            $io->writeln('  '.$this->unit->cronLine());
            $io->writeln('');

            return Command::SUCCESS;
        }

        if ((bool) $input->getOption('update')) {
            $path = $this->unit->updateExecStart();
            $io->success('Updated '.$path);
            $io->writeln('Load the change and restart the worker:');
            $io->writeln('');
            $io->writeln('  systemctl --user daemon-reload');
            $io->writeln('  systemctl --user restart '.$this->unit->serviceName());
            $io->writeln('');

            return Command::SUCCESS;
        }

        if ($this->unit->existingIsCurrent()) {
            $io->success('The service file is already correct: '.$this->unit->unitPath());

            return Command::SUCCESS;
        }

        $path = $this->unit->write();
        $io->success('Written: '.$path);
        self::printStartInstructions($io, $this->unit);

        return Command::SUCCESS;
    }

    /**
     * The block an owner reads once and follows. Shared with the installer so the wording cannot
     * drift between the two places it appears.
     *
     * ⚠ EVERY LINE IS COMPLETE — no placeholders, including the username in `loginctl`. That is the
     * one value an owner would otherwise have to fill in themselves, and the one command whose
     * purpose is not self-evident, so it also carries the half sentence explaining what happens
     * without it. A step somebody does not understand is a step they skip.
     */
    public static function printStartInstructions(SymfonyStyle $io, WorkerServiceUnit $unit): void
    {
        $io->writeln('Start it, and keep it running after you log out:');
        $io->writeln('');
        $io->writeln('  systemctl --user daemon-reload');
        $io->writeln('  systemctl --user enable --now '.$unit->serviceName());
        $io->writeln('  loginctl enable-linger '.$unit->username());
        $io->writeln('');
        $io->writeln('The last one matters: without it the worker stops the moment you log out of');
        $io->writeln('SSH, and e-mail silently stops with it — no error anywhere.');
        $io->writeln('');
        $io->writeln('Then check it took:');
        $io->writeln('');
        $io->writeln('  systemctl --user status '.$unit->serviceName());
        $io->writeln('');
    }
}
