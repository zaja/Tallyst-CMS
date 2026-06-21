<?php

namespace Tallyst\Media\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
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

    public function __construct(
        private readonly MediaRepository $media,
        private readonly MediaImageHelper $images,
        private readonly MediaUploader $uploader,
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
            return new Response('Nevažeći CSRF token.', Response::HTTP_FORBIDDEN);
        }

        $file = $this->firstUploadedFile($request);
        if (!$file instanceof UploadedFile) {
            return new Response('Nedostaje datoteka.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $media = $this->uploader->upload($file);
        } catch (MediaUploadException $e) {
            // 422 → FilePond shows the message as the item error.
            return new Response($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new Response((string) $media->getId(), Response::HTTP_OK, ['Content-Type' => 'text/plain']);
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
}
