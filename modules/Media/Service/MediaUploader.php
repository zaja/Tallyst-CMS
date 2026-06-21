<?php

namespace Tallyst\Media\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Tallyst\Media\Entity\Media;

/**
 * Programmatic upload path for a single image, used by the FilePond endpoint (process
 * + bulk). The EA Media create uses VichImageType directly, but BOTH paths validate the
 * SAME Assert\Image constraint on Media::$imageFile — that entity constraint is the
 * single source of truth (it does fileinfo-based mime detection), so the rules cannot
 * diverge. We deliberately do NOT duplicate the allowed-mime list here.
 *
 * On flush, Vich applies the SmartUniqueNamer and fills size/mimeType/originalName, and
 * the postPersist listener warms the Liip thumbnails — identical to the EA path.
 */
class MediaUploader
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
        private readonly MediaMetadataExtractor $metadata,
    ) {
    }

    /**
     * Validate + persist one uploaded image, returning the managed Media.
     *
     * @throws MediaUploadException when the file fails the entity's Assert\Image rule
     */
    public function upload(UploadedFile $file): Media
    {
        $media = new Media();
        $media->setImageFile($file);

        // Validate against the entity constraint (the single shared rule). Vich's
        // metadata isn't populated yet, but Assert\Image inspects the File directly.
        $violations = $this->validator->validate($media);
        if (\count($violations) > 0) {
            throw new MediaUploadException($violations->get(0)->getMessage());
        }

        // Auto-fill title/alt (only if empty) from IPTC/EXIF/filename, read from the temp
        // upload BEFORE Vich moves it. Same path as the modal upload, so it applies there
        // too.
        $this->metadata->applyToMedia($media, $file->getPathname(), $file->getClientOriginalName());

        $this->em->persist($media);
        $this->em->flush();

        return $media;
    }
}
