<?php

namespace App\Tests\Media;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Tallyst\Media\Entity\Media;
use Tallyst\Media\Service\MediaUploader;
use Tallyst\Media\Service\ThumbnailCacheNaming;

/**
 * Locks MediaCacheCleanupListener: thumbnails are deleted ONLY when a genuinely new file is
 * being stored, never when some other field on the same Media changes.
 *
 * ⚠ WHY THIS EXISTS: v1.8.0 shipped the listener with a `instanceof File` gate, which Vich's
 * own FileInjector satisfies right AFTER a successful upload. Any second flush on that Media in
 * the same request (the demo seeder's upload() → setTitle/setAlt/setIsDemo → flush) was read as
 * a replacement and deleted the just-warmed thumbnails — rows and originals intact, every
 * thumbnail gone, no error. testDemoSeederPatternKeepsThumbnails is that exact shape.
 *
 * ⚠ SAFETY (see the app:media:cache:clean incident in CLAUDE.md): Liip's resolver path is fixed
 * by container config at %kernel.project_dir%/public in EVERY environment, test included — a
 * test here touches the REAL public/media/cache. So: every fixture uses a unique, disposable
 * name, tearDown removes exactly its own rows/files, and each test asserts the total cache file
 * count returns to its starting value, so a mistake that deletes someone else's files fails loudly
 * instead of being discovered days later. The real demo seeder is NEVER invoked from a test.
 */
class MediaCacheCleanupListenerTest extends KernelTestCase
{
    private const FILTERS = ['thumb', 'medium', 'hero', 'favicon'];

    private EntityManagerInterface $em;
    private MediaUploader $uploader;
    private string $projectDir;
    /** @var int[] */
    private array $mediaIds = [];
    /** @var string[] absolute paths of temp source images to unlink */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->uploader = $container->get(MediaUploader::class);
        $this->projectDir = $container->getParameter('kernel.project_dir');
    }

    /**
     * THE REGRESSION. Upload, then change ordinary fields on the same Media in the same request
     * and flush again — the thumbnails warmed by the upload must survive.
     */
    public function testChangingTitleAndAltAfterUploadKeepsThumbnails(): void
    {
        $before = $this->totalCacheFiles();

        $media = $this->upload('keep-on-edit');
        $name = (string) $media->getImageName();
        self::assertSame(4, $this->existingThumbnails($name), 'upload must warm all four filters');

        $media->setTitle('Edited title')->setAlt('Edited alt');
        $this->em->flush();

        self::assertSame(4, $this->existingThumbnails($name), 'editing title/alt must NOT delete thumbnails');

        $this->deleteMedia($media);
        self::assertSame($before, $this->totalCacheFiles(), 'the cache must be back to its starting size');
    }

    /** A genuine replacement (a new UploadedFile) must still clean the OLD file's thumbnails. */
    public function testReplacingTheImageDeletesTheOldThumbnails(): void
    {
        $before = $this->totalCacheFiles();

        $media = $this->upload('replace-me');
        $oldName = (string) $media->getImageName();
        self::assertSame(4, $this->existingThumbnails($oldName));

        // Exactly what the EA form / MediaUploader do: set a new UploadedFile, then flush.
        $media->setImageFile($this->makeUploadedFile('replacement'));
        $this->em->flush();

        $newName = (string) $media->getImageName();
        self::assertNotSame($oldName, $newName, 'Vich must store the replacement under a new name');
        self::assertSame(0, $this->existingThumbnails($oldName), 'the OLD thumbnails must be deleted');
        self::assertSame(4, $this->existingThumbnails($newName), 'the NEW file must be warmed');

        $this->deleteMedia($media);
        self::assertSame($before, $this->totalCacheFiles());
    }

    /** Unchanged behaviour: removing the Media removes its thumbnails (preRemove path). */
    public function testDeletingTheMediaDeletesItsThumbnails(): void
    {
        $before = $this->totalCacheFiles();

        $media = $this->upload('delete-me');
        $name = (string) $media->getImageName();
        self::assertSame(4, $this->existingThumbnails($name));

        $this->deleteMedia($media);

        self::assertSame(0, $this->existingThumbnails($name), 'deleting a Media must delete its thumbnails');
        self::assertSame($before, $this->totalCacheFiles());
    }

    /**
     * The demo cycle's exact write shape, reproduced WITHOUT invoking the seeder (which would
     * write into the real project directories — see the safety note above): upload, then set
     * title/alt/isDemo and flush. This is what silently broke every demo image.
     */
    public function testDemoSeederPatternKeepsThumbnails(): void
    {
        $before = $this->totalCacheFiles();

        $media = $this->upload('demo-pattern');
        $name = (string) $media->getImageName();

        $media->setTitle('Arca demo — pattern')->setAlt('A demo illustration')->setIsDemo(true);
        $this->em->flush();

        self::assertSame(
            4,
            $this->existingThumbnails($name),
            'after the demo two-phase write every thumbnail must still exist (this is the v1.8.0 regression)'
        );

        $this->deleteMedia($media);
        self::assertSame($before, $this->totalCacheFiles());
    }

    // --- helpers --------------------------------------------------------------------------------

    private function upload(string $label): Media
    {
        $media = $this->uploader->upload($this->makeUploadedFile($label));
        $this->mediaIds[] = (int) $media->getId();

        return $media;
    }

    /** A real, valid PNG (Assert\Image inspects the file), under a unique disposable name. */
    private function makeUploadedFile(string $label): UploadedFile
    {
        $path = sys_get_temp_dir().'/tallyst-cachetest-'.$label.'-'.bin2hex(random_bytes(5)).'.png';
        $image = imagecreatetruecolor(120, 90);
        imagefill($image, 0, 0, imagecolorallocate($image, 40, 90, 150));
        imagepng($image, $path);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, basename($path), 'image/png', null, true);
    }

    /** How many of the four filters currently have a warmed file for this image. */
    private function existingThumbnails(string $imageName): int
    {
        $found = 0;
        foreach (self::FILTERS as $filter) {
            if (is_file($this->cacheFile($imageName, $filter))) {
                ++$found;
            }
        }

        return $found;
    }

    private function cacheFile(string $imageName, string $filter): string
    {
        return $this->projectDir.'/public/media/cache/'.$filter.'/'.ThumbnailCacheNaming::cachePath($imageName, $filter);
    }

    /** Guard rail: the whole cache must be the same size before and after each test. */
    private function totalCacheFiles(): int
    {
        $total = 0;
        foreach (self::FILTERS as $filter) {
            $dir = $this->projectDir.'/public/media/cache/'.$filter;
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    ++$total;
                }
            }
        }

        return $total;
    }

    private function deleteMedia(Media $media): void
    {
        $id = (int) $media->getId();
        $this->em->remove($media);
        $this->em->flush();
        $this->mediaIds = array_values(array_filter($this->mediaIds, static fn (int $kept): bool => $kept !== $id));
    }

    protected function tearDown(): void
    {
        // Remove anything a failing assertion left behind — entity removal takes the original
        // (Vich delete_on_remove) and the thumbnails (preRemove) with it.
        foreach ($this->mediaIds as $id) {
            if (null !== ($media = $this->em->getRepository(Media::class)->find($id))) {
                $this->em->remove($media);
            }
        }
        $this->em->flush();
        $this->mediaIds = [];

        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tempFiles = [];

        parent::tearDown();
    }
}
