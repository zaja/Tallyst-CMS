<?php

namespace Tallyst\Media\Controller\Admin;

use App\Repository\SettingRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Tallyst\Media\Form\Model\BrandingData;
use Tallyst\Media\Form\Type\BrandingType;
use Tallyst\Media\Repository\MediaRepository;

/**
 * Branding admin: site name + logo (a Media reference). Stored as Settings — the
 * logo is a LOOSE reference (logo_media_id, not a FK), so consumers must tolerate the
 * referenced Media being deleted (the render helper falls back to the site name).
 *
 * Rendered inside the EasyAdmin shell via the dashboardControllerFqcn route default.
 */
#[Route('/admin/branding', defaults: ['dashboardControllerFqcn' => 'App\Controller\Admin\DashboardController'])]
class BrandingController extends AbstractController
{
    public function __construct(
        private readonly SettingRepository $settings,
        private readonly MediaRepository $media,
    ) {
    }

    #[Route('', name: 'media_branding', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $data = new BrandingData();
        $data->siteName = $this->settings->get('site_name', 'Tallyst') ?? 'Tallyst';
        $logoId = $this->settings->get('logo_media_id');
        $data->logo = $logoId ? $this->media->find((int) $logoId) : null;

        $form = $this->createForm(BrandingType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->settings->set('site_name', '' !== $data->siteName ? $data->siteName : 'Tallyst');
            $this->settings->set('logo_media_id', null !== $data->logo ? (string) $data->logo->getId() : '');
            $this->addFlash('success', 'Branding je spremljen.');

            return $this->redirectToRoute('media_branding');
        }

        return $this->render('@Media/admin/branding.html.twig', ['form' => $form->createView()]);
    }
}
