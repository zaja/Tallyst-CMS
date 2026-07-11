<?php

namespace Tallyst\FormBuilder\Controller\Admin;

use App\Settings\SettingsManager;
use App\Settings\SettingsRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tallyst\FormBuilder\Service\TaxCatalog;

/**
 * Saves BOTH halves of Postavke → Porez in ONE submit: the `tax_enabled` MASTER switch AND the tax-rate
 * catalog. A dedicated FormBuilder route (both are FormBuilder-owned, like the Tax section) so the Core
 * SettingsController stays unaware of them. Mirrors the shipping-catalog editor: a single <form> the tab's
 * inputs bind to via the HTML5 form= attribute, POST + CSRF, then redirect back to the tab. The `default_row`
 * radio (a shared group carrying the chosen row's index) is folded into the rows before save. tax_enabled is
 * NOT a scalar SettingDefinition (so the generic Save is hidden) — it's persisted here via SettingsManager as
 * an explicit '1'/'0' string that `(bool) settings->get()` reads back correctly. ROLE_ADMIN.
 */
#[Route('/admin/settings/tax')]
#[IsGranted('ROLE_ADMIN')]
class TaxSettingsController extends AbstractController
{
    public function __construct(
        private readonly TaxCatalog $catalog,
        private readonly SettingsManager $settings,
        private readonly SettingsRegistry $registry,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/save', name: 'admin_settings_tax_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('settings_tax', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // Master switch — an unchecked checkbox submits nothing, so getBoolean() → false. Store an explicit
        // '1'/'0' string (TaxCalculator reads it as `(bool) get('tax_enabled')`; '0' is falsy in PHP).
        $this->settings->set('tax_enabled', $request->request->getBoolean('tax_enabled') ? '1' : '0');

        // Parallel-array rows: rates[i][key], rates[i][name], rates[i][rate]. The chosen default is a shared
        // radio (default_row = the selected row's index) — inject it into that row so the catalog marks it
        // default (the catalog also falls back to the first row when nothing is flagged).
        $rates = $request->request->all('rates');
        $defaultRow = $request->request->get('default_row');
        if (null !== $defaultRow && isset($rates[$defaultRow]) && is_array($rates[$defaultRow])) {
            $rates[$defaultRow]['default'] = true;
        }
        $this->catalog->save($rates);

        $this->addFlash('success', $this->translator->trans('admin.settings.tax.flash.saved', [], 'admin'));

        $tab = $this->registry->tabKeyForSection('tax');

        return $this->redirectToRoute('admin_settings_tab', ['tab' => $tab ?? 'tax']);
    }
}
