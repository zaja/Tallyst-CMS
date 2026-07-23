<?php

namespace Tallyst\Media\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Tallyst\Media\Entity\Media;
use Tallyst\Media\Service\ThumbnailCacheNaming;
use Tallyst\Media\Service\ThumbnailCleaner;

/**
 * One-off sweep for Liip cache files left behind by replaces/deletes that happened BEFORE
 * MediaCacheCleanupListener existed (or from any other source of drift) — the ongoing
 * leak is already prevented going forward; this is for the backlog that already
 * accumulated. Safe by construction: dry-run by default, real deletion only with
 * --force, and deletion always goes through ThumbnailCleaner (the SAME single place
 * MediaCacheCleanupListener uses) so the .webp-suffix logic is never duplicated.
 *
 * ⚠ This command will be run by OTHER SITES' OWNERS, on their own production installs, in
 * conditions we don't control — a wrong DATABASE_URL, a wrong APP_ENV, a half-finished
 * install. It's an IRREVERSIBLE bulk delete, so it must assume the inputs might be wrong
 * and refuse rather than trust them:
 *   1. Zero known names + a non-empty cache directory is NEVER a legitimate state (a real
 *      site with zero media wouldn't have a populated cache) — it's the exact signature of
 *      reading from the wrong database. Hard abort, in both dry-run and --force.
 *   2. An unreasonably high orphan ratio (>= ORPHAN_RATIO_CONFIRM_THRESHOLD) produces the
 *      SAME symptom as a wrong database, even though it can also be a genuinely dirty site —
 *      --force requires an extra explicit confirmation before deleting (or
 *      --i-know-what-im-doing to skip it deliberately); a non-interactive context with
 *      neither is refused rather than assumed "yes".
 *   3. Which database + environment this run is reading from is printed BEFORE any check,
 *      so an operator watching the output can abort in the first second if it's wrong.
 *
 * Scalability: the DB side is read via a scalar DQL query + toIterable() (never
 * findAll(), which would hydrate full Media entities for what's really just a list of
 * strings) into a simple name=>true lookup set — for a few thousand rows this is a small,
 * bounded index, not "loading everything into memory" in the sense that matters (that
 * would be slurping FILE CONTENTS, which this never does). The filesystem side is walked
 * with DirectoryIterator (a real lazy iterator — never glob()/scandir(), which return the
 * WHOLE directory listing as one array up front) across all four filter directories, so a
 * name whose warm was only partially successful (e.g. 3 of 4 filters) is still measured
 * and cleaned up file-by-file rather than assumed symmetric.
 */
#[AsCommand(
    name: 'app:media:cache:clean',
    description: 'Find (and, with --force, remove) orphaned Liip cache files — thumbnails for images no longer in the database. Dry-run by default.',
)]
class CleanCacheCommand extends Command
{
    /** Keep in sync with ThumbnailWarmer::FILTERS / ThumbnailCleaner::FILTERS. */
    private const FILTERS = ['thumb', 'medium', 'hero', 'favicon'];

    /** Print a running subtotal after this many orphans, so a huge sweep still shows progress. */
    private const PROGRESS_EVERY = 100;

    /**
     * A wrong-database run looks identical to a very-dirty-but-legitimate site: nearly (or
     * exactly) every cached name is "orphaned". 90% is high enough that a normally-drifting
     * real site rarely trips it, but low enough to have caught the incident that motivated
     * this guard (a test-database lookup against the real cache directory — 100% orphaned).
     */
    private const ORPHAN_RATIO_CONFIRM_THRESHOLD = 0.9;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ThumbnailCleaner $cleaner,
        /**
         * The Liip cache root (…/public/media/cache), NOT the project dir — deliberately
         * injectable/overridable on its own (not derived from a project-dir param this
         * class also uses for something else), so a test can point it at an isolated temp
         * directory without any special constructor-bypass or setter. This is exactly the
         * thing that went wrong before: a test used the REAL project dir (kernel.project_dir
         * is identical in every environment, test included) while the database was the
         * isolated test one — every real image looked unknown, and --force deleted the
         * entire real cache. Tests MUST override this to a temp path; production wiring
         * (below) computes it from the real project dir exactly once, at the DI boundary.
         */
        #[Autowire(expression: "parameter('kernel.project_dir') ~ '/public/media/cache'")]
        private readonly string $cacheRoot,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Actually delete the orphaned cache files. Without this, the command only reports what it would do.')
            ->addOption('i-know-what-im-doing', null, InputOption::VALUE_NONE, 'Skip the extra confirmation when an unusually high share of the cache looks orphaned (see ORPHAN_RATIO_CONFIRM_THRESHOLD).')
            ->setHelp(
                "Compares every image referenced in the Liip cache directories against the media "
                ."table's image_name column and reports (or, with --force, removes) any cache file "
                ."whose source image no longer exists in the database.\n\n"
                ."Always run without --force first to review what would be removed."
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');
        $skipRatioConfirm = (bool) $input->getOption('i-know-what-im-doing');
        $verbose = $output->isVerbose();

        $io->title($force
            ? 'Media cache cleanup — DELETING orphaned files (--force)'
            : 'Media cache cleanup — DRY RUN (nothing will be deleted)');

        if (!$force) {
            $io->note('This is a dry run. Re-run with --force to actually delete the files listed below.');
        }

        // ⚠ Brake 3 — transparency BEFORE any check, so an operator watching can abort
        // (Ctrl+C) the instant they see the wrong database or environment.
        $io->section('Reading from');
        $io->text([
            \sprintf('Environment : %s', $this->environment),
            \sprintf('Database    : %s', $this->em->getConnection()->getDatabase()),
            \sprintf('Cache root  : %s', $this->cacheRoot),
        ]);
        $io->newLine();

        $knownNames = $this->loadKnownImageNames();
        $io->text(\sprintf('%d image name(s) referenced in that database.', \count($knownNames)));
        $io->newLine();

        // Discover every (imageName => {filter => [path, size]}) group present on disk,
        // across all four filter directories, via DirectoryIterator (lazy — never
        // glob()/scandir() loading the whole listing at once).
        $foundAnyDir = false;
        $filesByName = [];
        foreach (self::FILTERS as $filter) {
            $dir = $this->cacheRoot.'/'.$filter.'/media/uploads';
            if (!is_dir($dir)) {
                continue;
            }
            $foundAnyDir = true;

            foreach (new \DirectoryIterator($dir) as $fileInfo) {
                if ($fileInfo->isDot() || !$fileInfo->isFile()) {
                    continue;
                }
                $filename = $fileInfo->getFilename();
                if (str_starts_with($filename, '.')) {
                    continue; // stray dotfiles (e.g. .gitkeep) — never treated as an image
                }

                $imageName = $this->recoverImageName($filename, $filter);
                $filesByName[$imageName][$filter] = [$fileInfo->getPathname(), $fileInfo->getSize()];
            }
        }

        if (!$foundAnyDir) {
            $io->success('No cache directories found — nothing to clean.');

            return Command::SUCCESS;
        }

        if ([] === $filesByName) {
            $io->success('The cache directories are empty — nothing to clean.');

            return Command::SUCCESS;
        }

        $totalNames = \count($filesByName);

        // ⚠ Brake 1 — a real site with zero media referenced in the database would also
        // have an empty cache directory. Zero known names alongside a non-empty cache is
        // never legitimate; it's the exact signature of reading from the wrong database.
        // Applies in BOTH modes — a dry-run report built on this data would be just as
        // wrong as a real deletion.
        if (0 === \count($knownNames)) {
            $io->error([
                \sprintf(
                    'The database returned ZERO image names, but the cache directory has %d. This '
                    .'is never a legitimate state for a real site.',
                    $totalNames
                ),
                'This is the signature of reading from the wrong database — a wrong DATABASE_URL, '
                .'a wrong APP_ENV, or a fresh/empty install pointed at someone else\'s cache '
                .'directory. Refusing to proceed. Check the "Reading from" section above.',
            ]);

            return Command::FAILURE;
        }

        $orphanNames = [];
        foreach ($filesByName as $imageName => $perFilter) {
            if (!isset($knownNames[$imageName])) {
                $orphanNames[] = $imageName;
            }
        }

        if ([] === $orphanNames) {
            $io->success('No orphaned cache files found.');

            return Command::SUCCESS;
        }

        $orphanRatio = \count($orphanNames) / $totalNames;

        // ⚠ Brake 2 — an unreasonably high orphan ratio produces the identical symptom to a
        // wrong database, even though it CAN also be a genuinely long-neglected real site.
        // Only gates actual deletion — a dry-run is safe to report regardless, but still
        // shows the same warning so the operator sees it before ever adding --force.
        if ($orphanRatio >= self::ORPHAN_RATIO_CONFIRM_THRESHOLD) {
            $io->warning([
                \sprintf(
                    '%d of %d cached image names (%.1f%%) look orphaned. This is unusually high, '
                    .'and it\'s the SAME signature a mismatched database produces — it does not '
                    .'automatically mean the site genuinely has that much stale cache.',
                    \count($orphanNames),
                    $totalNames,
                    $orphanRatio * 100,
                ),
                'Double-check the "Reading from" section above before continuing.',
            ]);

            if ($force && !$skipRatioConfirm) {
                if (!$input->isInteractive()) {
                    $io->error(
                        'Refusing to delete without confirmation in a non-interactive context '
                        .'(no TTY). Re-run interactively, or pass --i-know-what-im-doing if you '
                        .'have verified the database/environment above and are certain.'
                    );

                    return Command::FAILURE;
                }

                if (!$io->confirm(\sprintf('Proceed and delete these %d orphaned image(s)?', \count($orphanNames)), false)) {
                    $io->text('Cancelled — nothing was deleted.');

                    return Command::SUCCESS;
                }
            }
        }

        $orphanFiles = 0;
        $orphanBytes = 0;

        foreach ($orphanNames as $i => $imageName) {
            $perFilter = $filesByName[$imageName];
            $nameBytes = array_sum(array_column($perFilter, 1));
            $orphanFiles += \count($perFilter);
            $orphanBytes += $nameBytes;

            if ($verbose) {
                $io->text(\sprintf(
                    '  %s  (%d file%s, %s)%s',
                    $imageName,
                    \count($perFilter),
                    1 === \count($perFilter) ? '' : 's',
                    $this->formatBytes($nameBytes),
                    $force ? '' : ' — would remove',
                ));
            }

            if ($force) {
                $this->cleaner->remove($imageName);
            }

            if (0 === ($i + 1) % self::PROGRESS_EVERY) {
                $io->text(\sprintf(
                    '  … %d orphan(s) so far, %s',
                    $i + 1,
                    $this->formatBytes($orphanBytes),
                ));
            }
        }

        $io->newLine();
        $io->success(\sprintf(
            '%s %d orphaned image(s) — %d file(s), %s.%s',
            $force ? 'Removed' : 'Found',
            \count($orphanNames),
            $orphanFiles,
            $this->formatBytes($orphanBytes),
            $force ? '' : ' Re-run with --force to delete them.'.($verbose ? '' : ' Add -v to list every one.'),
        ));

        return Command::SUCCESS;
    }

    /**
     * @return array<string, true> a set — image_name => true, for O(1) lookups
     */
    private function loadKnownImageNames(): array
    {
        $query = $this->em->createQuery(
            'SELECT m.imageName FROM '.Media::class.' m WHERE m.imageName IS NOT NULL'
        );

        $names = [];
        foreach ($query->toIterable() as $row) {
            $names[$row['imageName']] = true;
        }

        // toIterable() keeps entity hydration state around per-row; nothing here persists
        // any Media entities, but clear() keeps this command's memory flat regardless of
        // how many rows exist.
        $this->em->clear();

        return $names;
    }

    /**
     * Reverses ThumbnailCacheNaming::cachePath() for one filter: webp filters
     * (thumb/medium/hero) carry a .webp suffix (double .webp.webp when the source name
     * itself already ends in .webp — reduces correctly here since only ONE trailing
     * .webp is ever stripped); favicon carries none.
     */
    private function recoverImageName(string $filename, string $filter): string
    {
        if (ThumbnailCacheNaming::isWebpFilter($filter) && str_ends_with($filename, '.webp')) {
            return substr($filename, 0, -\strlen('.webp'));
        }

        return $filename;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / (1024 * 1024), 2).' MB';
    }
}
