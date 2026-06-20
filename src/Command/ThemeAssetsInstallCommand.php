<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Publishes every theme's static assets (themes/<name>/public/) to
 * public/themes/<name>/ so the web server serves them directly — separate from
 * AssetMapper, which stays for app/admin JS. Symlinks with a copy fallback, like
 * Symfony's assets:install. Run after adding/changing a theme and on deploy.
 */
#[AsCommand(name: 'app:theme:assets:install', description: 'Publish theme public/ folders to public/themes/<name>/.')]
class ThemeAssetsInstallCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fs = new Filesystem();

        $themesDir = $this->projectDir.'/themes';
        if (!is_dir($themesDir)) {
            $io->warning('No themes/ directory.');

            return Command::SUCCESS;
        }

        $published = 0;
        foreach (scandir($themesDir) ?: [] as $name) {
            if ('.' === $name || '..' === $name) {
                continue;
            }

            $source = $themesDir.'/'.$name.'/public';
            if (!is_dir($source)) {
                continue;
            }

            $target = $this->projectDir.'/public/themes/'.$name;
            $fs->remove($target);
            $fs->mkdir(\dirname($target));

            try {
                $fs->symlink($source, $target);
                $io->writeln(\sprintf('• %s → public/themes/%s (symlink)', $name, $name));
            } catch (\Throwable) {
                $fs->mirror($source, $target);
                $io->writeln(\sprintf('• %s → public/themes/%s (copy)', $name, $name));
            }

            ++$published;
        }

        $io->success(\sprintf('Published %d theme(s).', $published));

        return Command::SUCCESS;
    }
}
