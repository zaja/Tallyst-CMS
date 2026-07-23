<?php

namespace Tallyst\Media\Service;

use Doctrine\ORM\EntityManagerInterface;
use Liip\ImagineBundle\Imagine\Filter\FilterManager;
use Liip\ImagineBundle\Model\FileBinary;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tallyst\Media\Entity\Media;
use Vich\UploaderBundle\Storage\StorageInterface;

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
    /**
     * Mime → Imagine format string, for the exact same raster types Assert\Image allows
     * (Media::$imageFile). Deliberately an explicit allowlist rather than a generic
     * mime→extension guess (the "curated, not guessed" convention used elsewhere in this
     * module — see MediaImageHelper::ALIGN_CLASSES) — keeps the crop's re-encode format
     * unambiguous and easy to audit alongside the Assert\Image list it mirrors.
     */
    private const CROPPABLE_FORMATS = [
        'image/jpeg' => 'jpeg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
        private readonly MediaMetadataExtractor $metadata,
        private readonly FilterManager $filterManager,
        private readonly TranslatorInterface $translator,
        private readonly StorageInterface $storage,
    ) {
    }

    /**
     * Validate + persist one uploaded image, returning the managed Media.
     *
     * When $cropRect is given, the file is cropped SERVER-SIDE (via Liip's own `crop` filter
     * loader, applied ad hoc through FilterManager — no filter_set/YAML change needed) BEFORE
     * anything else happens: the cropped result becomes the file that gets validated,
     * metadata-extracted, and persisted. The original, uncropped bytes are never written to
     * managed storage — Vich MOVES the file it's handed on flush, so cropping in place here
     * means the crop simply IS the stored original (same "Vich moves, doesn't copy" behaviour
     * DemoSeedCommand relies on — see CLAUDE.md).
     *
     * @param null|array{x:int,y:int,width:int,height:int} $cropRect already validated by the
     *   caller (MediaLibraryController) — positive ints, within the uploaded file's real
     *   pixel bounds. This method trusts that contract; it does not re-validate coordinates.
     *
     * @throws MediaUploadException when the file fails the entity's Assert\Image rule
     */
    public function upload(UploadedFile $file, ?array $cropRect = null): Media
    {
        $cropTempPath = null;

        try {
            if (null !== $cropRect) {
                [$file, $cropTempPath] = $this->cropToTempFile($file, $cropRect);
            }

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

            // Vich has moved the (possibly cropped) file into managed storage by now — there
            // is nothing left at $cropTempPath to clean up.
            $cropTempPath = null;

            return $media;
        } finally {
            if (null !== $cropTempPath && is_file($cropTempPath)) {
                @unlink($cropTempPath);
            }
        }
    }

    /**
     * Crops $file to $rect via Liip's `crop` filter loader (arbitrary pixel start/size —
     * already registered as a core Liip service, no filter_set needed) applied through the
     * FilterManager with an AD-HOC config (not a named YAML preset). Returns a new
     * UploadedFile over a fresh temp file holding the cropped result, plus that temp path so
     * the caller can clean it up if it never reaches Vich.
     *
     * The re-encode format is NOT overridden in the filter config — FilterManager defaults
     * the export format to the source binary's own format (set below from the file's REAL,
     * content-sniffed mime type via getMimeType(), never the client-claimed one), so a JPEG
     * upload stays a JPEG, PNG stays PNG, etc.
     *
     * @param array{x:int,y:int,width:int,height:int} $rect
     *
     * @return array{0: UploadedFile, 1: string}
     */
    private function cropToTempFile(UploadedFile $file, array $rect): array
    {
        $mimeType = $file->getMimeType();
        $format = self::CROPPABLE_FORMATS[$mimeType] ?? null;
        if (null === $format) {
            // Not one of the raster types Assert\Image allows — reuse its own message rather
            // than guessing a format.
            throw new MediaUploadException($this->translator->trans('validation.media.image_only', [], 'validators'));
        }

        $source = new FileBinary($file->getPathname(), $mimeType, $format);
        $cropped = $this->filterManager->apply($source, [
            'filters' => [
                'crop' => [
                    'start' => [$rect['x'], $rect['y']],
                    'size' => [$rect['width'], $rect['height']],
                ],
            ],
            'quality' => 90,
        ]);

        $tempPath = tempnam(sys_get_temp_dir(), 'tallyst_crop_');
        if (false === $tempPath) {
            throw new MediaUploadException($this->translator->trans('validation.media.crop_failed', [], 'validators'));
        }
        file_put_contents($tempPath, $cropped->getContent());

        // $test = true: this is a programmatically-built file, not a real HTTP upload, so
        // UploadedFile must not enforce the is_uploaded_file() check (same pattern Symfony
        // itself documents for exactly this "transform then re-wrap" use case).
        $newFile = new UploadedFile($tempPath, $file->getClientOriginalName(), $cropped->getMimeType(), null, true);

        return [$newFile, $tempPath];
    }

    /**
     * "Zamijeni ovu sliku" — crops $media's CURRENT stored file and assigns the result back
     * onto the SAME entity. This goes through the identical Vich inject mechanism the Edit
     * form's own file-replace already uses: SmartUniqueNamer gives the cropped result a
     * brand-new imageName on flush, so nothing is ever overwritten in place under the OLD
     * name — deliberately avoiding the trap where a same-name overwrite would leave stale
     * Liip thumbnails served forever (isStored() would find them already cached under the
     * unchanged name). MediaThumbnailListener's postUpdate hook warms fresh thumbnails
     * under the new name automatically. The entity's id/title/alt are untouched.
     *
     * @param array{x:int,y:int,width:int,height:int} $requestedRect already parsed as clean
     *   positive integers by the caller; bounds-checked here against the REAL current image
     *
     * @throws MediaUploadException on a missing/unreadable source file, an out-of-bounds
     *   rect, or a validation failure on the cropped result
     */
    public function replaceWithCrop(Media $media, array $requestedRect): void
    {
        $sourcePath = $this->resolveStoredPath($media);
        $rect = $this->boundedRect($requestedRect, $sourcePath);
        $sourceFile = $this->wrapStoredFile($sourcePath, $media->getOriginalName());
        $cropTempPath = null;

        try {
            [$file, $cropTempPath] = $this->cropToTempFile($sourceFile, $rect);
            $media->setImageFile($file);

            $violations = $this->validator->validate($media);
            if (\count($violations) > 0) {
                throw new MediaUploadException($violations->get(0)->getMessage());
            }

            // Dimensions are factual, not user input (see MediaMetadataExtractor), so they
            // MUST be refreshed to the cropped size — unlike title/alt (only-if-empty, so
            // this call leaves them untouched here since an existing Media already has
            // them set).
            $this->metadata->applyToMedia($media, $file->getPathname(), $media->getOriginalName());

            $this->em->flush();
            $cropTempPath = null;
        } finally {
            if (null !== $cropTempPath && is_file($cropTempPath)) {
                @unlink($cropTempPath);
            }
        }
    }

    /**
     * "Spremi kao novu sliku" — crops $source's CURRENT stored file into a brand-new Media
     * row via the existing upload() path (same crop mechanism, same auto-fill). Title/alt
     * are then copied from $source — a crop is a derivative of the same image, so reusing
     * upload()'s own metadata-extractor auto-fill would re-derive them from the filename
     * and silently discard whatever the admin had typed on the original. The title gets a
     * translated "(crop)" suffix so the two rows are told apart in the list; when the
     * source has neither title nor alt, upload()'s own auto-fill is left as-is.
     *
     * @param array{x:int,y:int,width:int,height:int} $requestedRect already parsed as clean
     *   positive integers by the caller; bounds-checked here against the REAL current image
     *
     * @throws MediaUploadException on a missing/unreadable source file, an out-of-bounds
     *   rect, or a validation failure on the cropped result
     */
    public function saveAsNewFromCrop(Media $source, array $requestedRect): Media
    {
        $sourcePath = $this->resolveStoredPath($source);
        $rect = $this->boundedRect($requestedRect, $sourcePath);
        $sourceFile = $this->wrapStoredFile($sourcePath, $source->getOriginalName());

        $media = $this->upload($sourceFile, $rect);

        $needsFlush = false;
        if ($source->getTitle()) {
            $media->setTitle($this->translator->trans('admin.media.crop_existing.new_title', ['%title%' => $source->getTitle()], 'admin'));
            $needsFlush = true;
        }
        if ($source->getAlt()) {
            $media->setAlt($source->getAlt());
            $needsFlush = true;
        }
        if ($needsFlush) {
            $this->em->flush();
        }

        return $media;
    }

    /**
     * Absolute filesystem path of $media's CURRENTLY stored file, resolved through Vich's
     * own StorageInterface (the same config-driven mechanism `vich_uploader_asset()` uses
     * for the public URL) — never a hand-built '/media/uploads/…' path, so a future change
     * to the upload mapping can't silently diverge from where we actually read.
     */
    private function resolveStoredPath(Media $media): string
    {
        $path = $this->storage->resolvePath($media, 'imageFile');
        if (null === $path || !is_file($path)) {
            throw new MediaUploadException($this->translator->trans('validation.media.source_missing', [], 'validators'));
        }

        return $path;
    }

    /**
     * Bounds-checks a structurally-valid rect against the REAL pixel dimensions of $path
     * (read via getimagesize — never trusted from the client), mirroring
     * MediaLibraryController::extractCropRect's upload-time check. Unlike that upload path
     * (where an invalid rect silently degrades to "no crop"), cropping an existing image
     * IS the entire point of this action, so an invalid rect is a hard error here.
     *
     * @param array{x:int,y:int,width:int,height:int} $rect
     *
     * @return array{x:int,y:int,width:int,height:int}
     */
    private function boundedRect(array $rect, string $path): array
    {
        if ($rect['width'] < 1 || $rect['height'] < 1) {
            throw new MediaUploadException($this->translator->trans('validation.media.crop_out_of_bounds', [], 'validators'));
        }

        $dimensions = @getimagesize($path);
        if (false === $dimensions) {
            throw new MediaUploadException($this->translator->trans('validation.media.image_only', [], 'validators'));
        }
        [$naturalWidth, $naturalHeight] = $dimensions;

        if ($rect['x'] + $rect['width'] > $naturalWidth || $rect['y'] + $rect['height'] > $naturalHeight) {
            throw new MediaUploadException($this->translator->trans('validation.media.crop_out_of_bounds', [], 'validators'));
        }

        return $rect;
    }

    /**
     * Wraps an on-disk file as an UploadedFile ($test = true — a programmatically-built
     * file, not a real HTTP upload) so it can flow through cropToTempFile()/upload() exactly
     * like a freshly-selected one. getMimeType() always real-sniffs from the file's actual
     * content regardless of what's passed here (Symfony File behaviour, unaffected by the
     * constructor's $mimeType arg — that only feeds getClientMimeType()).
     */
    private function wrapStoredFile(string $path, ?string $originalName): UploadedFile
    {
        return new UploadedFile($path, $originalName ?? basename($path), null, null, true);
    }
}
