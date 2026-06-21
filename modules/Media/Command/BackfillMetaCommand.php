<?php

namespace Tallyst\Media\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Tallyst\Media\Repository\MediaRepository;
use Tallyst\Media\Service\MediaMetadataExtractor;

/**
 * One-time backfill: fills title/alt for existing media that are missing them, using the
 * SAME logic as upload (IPTC/EXIF from disk, else the original filename). Touches only
 * empty fields. Safe even if a file is gone — it degrades to the filename.
 */
#[AsCommand(name: 'app:media:backfill-meta', description: 'Backfill title/alt for media missing them.')]
class BackfillMetaCommand extends Command
{
    public function __construct(
        private readonly MediaRepository $media,
        private readonly MediaMetadataExtractor $metadata,
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $uploadDir = $this->projectDir.'/public/media/uploads/';
        $updated = 0;

        foreach ($this->media->findMissingMeta() as $media) {
            $before = [$media->getTitle(), $media->getAlt()];
            $path = null !== $media->getImageName() ? $uploadDir.$media->getImageName() : '';

            $this->metadata->applyToMedia($media, $path, $media->getOriginalName());

            if ([$media->getTitle(), $media->getAlt()] !== $before) {
                ++$updated;
            }
        }

        $this->em->flush();
        $io->success(\sprintf('Backfilled metadata for %d media.', $updated));

        return Command::SUCCESS;
    }
}
