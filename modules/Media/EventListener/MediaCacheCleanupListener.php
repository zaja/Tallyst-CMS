<?php

namespace Tallyst\Media\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Tallyst\Media\Entity\Media;
use Tallyst\Media\Service\ThumbnailCleaner;
use Vich\UploaderBundle\FileAbstraction\ReplacingFile;

/**
 * Cleans up a Media's Liip cache files when its stored image is replaced or the Media
 * itself is deleted — the cleanup counterpart to MediaThumbnailListener's warm-on-save.
 * Without this, both paths only ever remove/replace the ORIGINAL file (Vich's own
 * RemoveListener / CleanListener, delete_on_remove / delete_on_update) and leave every
 * cached thumbnail (thumb/medium/hero/favicon) orphaned on disk forever.
 *
 * #[AsDoctrineListener] (a plain, entity-agnostic Doctrine listener), NOT
 * #[AsEntityListener]: this needs preRemove + preUpdate + postFlush sharing state across
 * ONE flush, and postFlush isn't tied to a specific entity at all — the same pattern
 * Vich's own RemoveListener/CleanListener use (preRemove/preUpdate collect, postFlush or a
 * later listener acts), not the postPersist/postUpdate entity-listener style
 * MediaThumbnailListener uses (those two ARE entity-scoped events, this trio isn't fully).
 *
 * ⚠ Cleanup is deferred to postFlush, not done inline in preRemove/preUpdate — a
 * transaction that later rolls back must delete nothing. Matches Vich's own timing.
 * Source-confirmed (UnitOfWork::commit()): dispatchPostFlushEvent() is only reached AFTER
 * a successful transaction commit — a failed/rolled-back flush never gets there, so this
 * listener's postFlush can never fire for a flush that didn't really happen.
 *
 * ⚠ preUpdate does NOT use PreUpdateEventArgs::hasChangedField()/getOldValue('imageName')
 * — MEASURED (not assumed) to be unreliable here: Vich's OWN CleanListener (priority 50)
 * and UploadListener (priority 0) each call $adapter->recomputeChangeSet() during their
 * own preUpdate handling (delete_on_update / the actual rename+upload), and
 * UnitOfWork::recomputeSingleEntityChangeSet() OVERWRITES `originalEntityData[$oid]` with
 * the entity's CURRENT state every time it runs (UnitOfWork.php ~line 1035) — by the time
 * two such recomputes have happened (one before the rename, one after), getOldValue()
 * reliably reports null instead of the true prior name (verified via a throwaway test
 * logging UnitOfWork::getOriginalEntityData() directly — it holds the NEW name, not the
 * old one, at the point our lower-priority listener used to run).
 *
 * FIX: run at a HIGHER priority (100) than Vich's CleanListener (50) so this listener's
 * preUpdate fires FIRST, before any Vich listener has touched imageName — at that point
 * the entity's plain PHP property still holds the true old name, read directly rather
 * than through Doctrine's changeset bookkeeping. imageFile isn't a mapped column, so it
 * never appears in any Doctrine changeset at all.
 *
 * ⚠ THE "IS THIS A REPLACEMENT?" PREDICATE MUST STAY EXACTLY VICH'S OWN — `instanceof
 * UploadedFile || instanceof ReplacingFile` — NOT the broader `instanceof File`.
 * Vich uses this exact pair everywhere it decides whether a new file is being stored:
 * UploadHandler::hasUploadedFile() (:120), AbstractStorage::upload()/remove() (:32, :53,
 * :62), FileExtensionTrait (:31, :45), FileRequiredValidator (:34, :39). Crucially
 * AbstractStorage::upload() RETURNS EARLY for anything else — so if this predicate says
 * "not a replacement", Vich has stored nothing either, the file on disk is unchanged, and
 * its thumbnails are still valid. The two are the SAME decision made with the SAME test,
 * which is why a narrower check here can never miss a genuine replacement.
 *
 * ⚠ WHY `instanceof File` WAS WRONG (the v1.8.0 regression this replaced): after a
 * successful upload Vich's FileInjector (:25) sets `new File($storedPath, false)` BACK
 * onto $imageFile. That injected File is the signal "I just stored this", not "a new file
 * is coming" — but it satisfies `instanceof File`. So ANY later update of that same Media
 * in the SAME request (e.g. DemoSeedCommand's upload() → setTitle/setAlt/setIsDemo →
 * flush) was read as a replacement and deleted the thumbnails MediaThumbnailListener had
 * just warmed. Result: rows + originals intact, every thumbnail gone, no error anywhere.
 * Both real replacement paths set an UploadedFile (MediaUploader::upload() :77 and
 * replaceWithCrop() :182, the latter via cropToTempFile()), so both stay covered.
 */
#[AsDoctrineListener(event: Events::preRemove)]
#[AsDoctrineListener(event: Events::preUpdate, priority: 100)]
#[AsDoctrineListener(event: Events::postFlush)]
class MediaCacheCleanupListener
{
    /** @var string[] imageName values to clean up once the flush actually commits. */
    private array $pendingCleanup = [];

    public function __construct(
        private readonly ThumbnailCleaner $cleaner,
    ) {
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof Media && $entity->getImageName()) {
            $this->pendingCleanup[] = $entity->getImageName();
        }
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Media || !$this->isReplacement($entity->getImageFile())) {
            return; // no new file being stored on this update — nothing to clean up
        }

        $old = $entity->getImageName();
        if (\is_string($old) && '' !== $old) {
            $this->pendingCleanup[] = $old;
        }
    }

    /**
     * Vich's own "a new file is being stored" test, mirrored exactly (see the class docblock).
     * Deliberately NOT `instanceof File`: Vich injects a plain File back onto the property
     * AFTER a successful upload, which means "already stored", not "replacing".
     */
    private function isReplacement(?File $file): bool
    {
        return $file instanceof UploadedFile || $file instanceof ReplacingFile;
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        foreach ($this->pendingCleanup as $imageName) {
            $this->cleaner->remove($imageName);
        }
        $this->pendingCleanup = [];
    }
}
