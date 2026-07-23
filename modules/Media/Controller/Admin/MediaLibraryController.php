<?php

namespace Tallyst\Media\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tallyst\Media\Entity\Media;
use Tallyst\Media\Repository\MediaRepository;
use Tallyst\Media\Service\MediaImageHelper;
use Tallyst\Media\Service\MediaUploadException;
use Tallyst\Media\Service\MediaUploader;

/**
 * AJAX/JSON backend for the reusable media-library component. These are NOT EA-shell
 * pages (no dashboardControllerFqcn default) — they return JSON / plain text. They live
 * under ^/admin so security.yaml already enforces ROLE_ADMIN (logged-out → 302 login).
 *
 * Paths deliberately sit OUTSIDE /admin/media/ (note the hyphen): EasyAdmin's pretty
 * URLs register `/admin/media/{entityId}` for the Media CRUD, which would otherwise
 * swallow /admin/media/library as a "detail" request and 500 on getEntity().
 *
 * ROLE_EDITOR (NOT ROLE_ADMIN): these endpoints power content editing — the editor's image
 * insert + the featured-image picker — so editors must reach them.
 */
#[Route('/admin')]
#[IsGranted('ROLE_EDITOR')]
class MediaLibraryController extends AbstractController
{
    /** CSRF token id shared by the page (csrf_token) and this endpoint's check. */
    public const UPLOAD_CSRF_ID = 'media_upload';

    /** CSRF token id for the existing-image crop actions below (replace / save-as-new). */
    public const CROP_CSRF_ID = 'media_crop';

    public function __construct(
        private readonly MediaRepository $media,
        private readonly MediaImageHelper $images,
        private readonly MediaUploader $uploader,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Paginated grid feed for the library. ?q= filters name/alt/title, ?page= paginates.
     */
    #[Route('/media-library', name: 'media_library_index', methods: ['GET'])]
    public function library(Request $request): JsonResponse
    {
        $q = $request->query->get('q');
        $page = $request->query->getInt('page', 1);

        $result = $this->media->searchPaginated($q, $page, 24);

        $items = [];
        foreach ($result['items'] as $media) {
            $items[] = [
                'id' => $media->getId(),
                'thumbUrl' => $this->images->url($media->getImageName(), 'thumb'),
                // Editor display size (Liip 'medium') — matches what toEditorHtml resolves
                // on load, so a freshly inserted image isn't smaller than after reload.
                'displayUrl' => $this->images->url($media->getImageName(), 'medium'),
                'name' => (string) $media,
                'alt' => $media->getAlt() ?? '',
            ];
        }

        return $this->json([
            'items' => $items,
            'page' => max(1, $page),
            'hasMore' => $result['hasMore'],
        ]);
    }

    /**
     * FilePond "process" target: one file per request. Returns the new Media id as
     * PLAIN TEXT (what FilePond stores as the server id). CSRF protected via the
     * X-CSRF-Token header (token rendered into the page).
     */
    #[Route('/media-upload', name: 'media_upload', methods: ['POST'])]
    public function upload(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::UPLOAD_CSRF_ID, (string) $request->headers->get('X-CSRF-Token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        $file = $this->firstUploadedFile($request);
        if (!$file instanceof UploadedFile) {
            return new Response('Nedostaje datoteka.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $media = $this->uploader->upload($file, $this->extractCropRect($request, $file));
        } catch (MediaUploadException $e) {
            // 422 → FilePond shows the message as the item error.
            return new Response($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new Response((string) $media->getId(), Response::HTTP_OK, ['Content-Type' => 'text/plain']);
    }

    /**
     * "Zamijeni ovu sliku" — crops the Media's CURRENT stored file in place (see
     * MediaUploader::replaceWithCrop; a brand-new imageName under the hood, never a
     * same-path overwrite). Redirects back to its own edit page so the admin immediately
     * sees the new preview — a plain JS `location.href` assignment to the SAME URL still
     * forces a real navigation/reload.
     */
    #[Route('/media-crop/{id}/replace', name: 'media_crop_replace', methods: ['POST'])]
    public function cropReplace(int $id, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CROP_CSRF_ID, (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => $this->translator->trans('admin.media.crop_existing.error_csrf', [], 'admin')], Response::HTTP_FORBIDDEN);
        }

        $media = $this->media->find($id);
        if (!$media instanceof Media) {
            return $this->json(['error' => $this->translator->trans('admin.media.crop_existing.error_not_found', [], 'admin')], Response::HTTP_NOT_FOUND);
        }

        $rect = $this->parseCropRectFields($request);
        if (null === $rect) {
            return $this->json(['error' => $this->translator->trans('admin.media.crop_existing.error_invalid_rect', [], 'admin')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->uploader->replaceWithCrop($media, $rect);
        } catch (MediaUploadException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('success', $this->translator->trans('admin.media.crop_existing.flash.replaced', [], 'admin'));

        return $this->json(['redirect' => $this->generateUrl('admin_media_edit', ['entityId' => $media->getId()])]);
    }

    /**
     * "Spremi kao novu sliku" — crops the Media's CURRENT stored file into a brand-new
     * Media row (see MediaUploader::saveAsNewFromCrop). Redirects to the NEW item's own
     * edit page — that's the thing the admin just created.
     */
    #[Route('/media-crop/{id}/save-new', name: 'media_crop_save_new', methods: ['POST'])]
    public function cropSaveNew(int $id, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CROP_CSRF_ID, (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => $this->translator->trans('admin.media.crop_existing.error_csrf', [], 'admin')], Response::HTTP_FORBIDDEN);
        }

        $source = $this->media->find($id);
        if (!$source instanceof Media) {
            return $this->json(['error' => $this->translator->trans('admin.media.crop_existing.error_not_found', [], 'admin')], Response::HTTP_NOT_FOUND);
        }

        $rect = $this->parseCropRectFields($request);
        if (null === $rect) {
            return $this->json(['error' => $this->translator->trans('admin.media.crop_existing.error_invalid_rect', [], 'admin')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $new = $this->uploader->saveAsNewFromCrop($source, $rect);
        } catch (MediaUploadException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('success', $this->translator->trans('admin.media.crop_existing.flash.saved_new', [], 'admin'));

        return $this->json(['redirect' => $this->generateUrl('admin_media_edit', ['entityId' => $new->getId()])]);
    }

    /**
     * Parses crop_x/y/w/h into clean positive integers — the STRUCTURAL half of what
     * extractCropRect() does below for the upload path. Deliberately separate from (and not
     * shared with) extractCropRect: that method's bounds-check reads the UPLOADED file's
     * dimensions and quietly returns null on anything invalid ("no crop" is a fine
     * fallback there); these two crop-an-existing-image actions bounds-check against the
     * CURRENT stored file instead (inside MediaUploader, which already has to open that
     * file to crop it) and treat an invalid rect as a hard error, per KORAK 2's design —
     * cropping IS the entire point of these actions, so there is no silent fallback.
     *
     * @return null|array{x:int,y:int,width:int,height:int}
     */
    private function parseCropRectFields(Request $request): ?array
    {
        $raw = [
            'x' => $request->request->get('crop_x'),
            'y' => $request->request->get('crop_y'),
            'w' => $request->request->get('crop_w'),
            'h' => $request->request->get('crop_h'),
        ];

        if (\in_array(null, $raw, true) || \in_array('', $raw, true)) {
            return null;
        }

        foreach ($raw as $value) {
            if (!\is_scalar($value) || 1 !== \preg_match('/^\d+$/', (string) $value)) {
                return null;
            }
        }

        $width = (int) $raw['w'];
        $height = (int) $raw['h'];
        if ($width < 1 || $height < 1) {
            return null;
        }

        return ['x' => (int) $raw['x'], 'y' => (int) $raw['y'], 'width' => $width, 'height' => $height];
    }

    /** First uploaded file regardless of FilePond's field name. */
    private function firstUploadedFile(Request $request): ?UploadedFile
    {
        foreach ($request->files->all() as $value) {
            if ($value instanceof UploadedFile) {
                return $value;
            }
            if (\is_array($value)) {
                foreach ($value as $inner) {
                    if ($inner instanceof UploadedFile) {
                        return $inner;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Reads the optional crop_x/crop_y/crop_w/crop_h fields sent alongside the file (only
     * present when the admin confirmed a crop in the client-side overlay — "Upload without
     * crop" sends none). Returns null ("no crop") unless ALL FOUR are present AND valid:
     * clean non-negative integers, width/height > 0, and the rect fits inside the UPLOADED
     * file's REAL pixel dimensions (read server-side via getimagesize — the client's numbers
     * are never trusted as-is). Cropping is an offer, not a requirement, so anything invalid
     * or partial silently degrades to an uncropped upload rather than rejecting the request —
     * but an out-of-range or malformed rect is NEVER passed through to the cropper.
     *
     * @return null|array{x:int,y:int,width:int,height:int}
     */
    private function extractCropRect(Request $request, UploadedFile $file): ?array
    {
        $raw = [
            'x' => $request->request->get('crop_x'),
            'y' => $request->request->get('crop_y'),
            'w' => $request->request->get('crop_w'),
            'h' => $request->request->get('crop_h'),
        ];

        if (\in_array(null, $raw, true) || \in_array('', $raw, true)) {
            return null;
        }

        foreach ($raw as $value) {
            if (!\is_scalar($value) || 1 !== \preg_match('/^\d+$/', (string) $value)) {
                return null;
            }
        }

        $x = (int) $raw['x'];
        $y = (int) $raw['y'];
        $width = (int) $raw['w'];
        $height = (int) $raw['h'];

        if ($width < 1 || $height < 1) {
            return null;
        }

        $dimensions = @getimagesize($file->getPathname());
        if (false === $dimensions) {
            return null; // not a readable image — let the normal Assert\Image path reject it
        }
        [$naturalWidth, $naturalHeight] = $dimensions;

        if ($x + $width > $naturalWidth || $y + $height > $naturalHeight) {
            return null;
        }

        return ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height];
    }
}
