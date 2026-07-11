<?php

namespace Tallyst\FormBuilder\Controller\Admin;

use App\Settings\SettingsRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tallyst\FormBuilder\Service\ShippingCatalog;

/**
 * Saves the shipping-method catalog edited in Postavke → Dostava. A dedicated FormBuilder route (the
 * catalog is FormBuilder-owned, like the Tax section) so the Core SettingsController stays unaware of
 * shipping. Mirrors the test-mail form: a separate <form> the tab's inputs bind to via the HTML5 form=
 * attribute, POST + CSRF, then redirect back to the tab. ROLE_ADMIN (settings are admin-only).
 *
 * A pure redirect — no EA-shell render — so no dashboardControllerFqcn default is needed (the redirect
 * target builds its own AdminContext). The two-segment path can't be captured by /admin/settings/{tab}.
 */
#[Route('/admin/settings/shipping')]
#[IsGranted('ROLE_ADMIN')]
class ShippingSettingsController extends AbstractController
{
    public function __construct(
        private readonly ShippingCatalog $catalog,
        private readonly SettingsRegistry $registry,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/save', name: 'admin_settings_shipping_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('settings_shipping', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // Parallel-array rows: methods[i][key], methods[i][label], methods[i][price]. The catalog cleans
        // them (drops empty-label rows, generates/keeps stable keys, converts price major→minor).
        $methods = $request->request->all('methods');
        $this->catalog->save($methods);

        $this->addFlash('success', $this->translator->trans('admin.settings.shipping.flash.saved', [], 'admin'));

        $tab = $this->registry->tabKeyForSection('shipping');

        return $this->redirectToRoute('admin_settings_tab', ['tab' => $tab ?? 'shipping']);
    }
}
