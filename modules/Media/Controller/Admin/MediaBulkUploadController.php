<?php

namespace Tallyst\Media\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Bulk upload page: a FilePond drag&drop panel that posts each dropped image to the
 * media_upload endpoint (creating Media). Rendered INSIDE the EA shell via the
 * dashboardControllerFqcn route default (so the sidebar/header are present).
 *
 * Path sits OUTSIDE /admin/media/ (hyphen) to avoid colliding with EasyAdmin's
 * `/admin/media/{entityId}` Media CRUD detail route.
 */
#[Route('/admin/media-bulk-upload', defaults: ['dashboardControllerFqcn' => 'App\Controller\Admin\DashboardController'])]
class MediaBulkUploadController extends AbstractController
{
    #[Route('', name: 'media_bulk_upload', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@Media/admin/bulk_upload.html.twig');
    }
}
