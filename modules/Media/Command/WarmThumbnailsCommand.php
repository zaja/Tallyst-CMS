<?php

namespace Tallyst\Media\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tallyst\Media\Repository\MediaRepository;
use Tallyst\Media\Service\ThumbnailWarmer;

/**
 * Warms thumbnails for ALL existing media. Run once after deploying this feature (for
 * images uploaded before warming existed) and whenever filter_sets change.
 */
#[AsCommand(name: 'app:media:thumbnails:warm', description: 'Generate Liip thumbnails for all media.')]
class WarmThumbnailsCommand extends Command
{
    public function __construct(
        private readonly MediaRepository $media,
        private readonly ThumbnailWarmer $warmer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = 0;

        foreach ($this->media->findAll() as $media) {
            $this->warmer->warmMedia($media);
            ++$count;
        }

        $io->success(\sprintf('Warmed thumbnails for %d media.', $count));

        return Command::SUCCESS;
    }
}
