<?php

namespace Tallyst\Media\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Tallyst\Media\Entity\Media;
use Tallyst\Media\Service\ThumbnailWarmer;

/**
 * Warms a media's thumbnails right after it is saved (Vich has already moved the file
 * by postPersist/postUpdate). isStored() makes re-saves (alt/title edits) cheap no-ops.
 */
#[AsEntityListener(event: Events::postPersist, method: 'warm', entity: Media::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'warm', entity: Media::class)]
class MediaThumbnailListener
{
    public function __construct(
        private readonly ThumbnailWarmer $warmer,
    ) {
    }

    public function warm(Media $media): void
    {
        $this->warmer->warmMedia($media);
    }
}
