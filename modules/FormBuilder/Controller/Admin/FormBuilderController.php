<?php

namespace Tallyst\FormBuilder\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\FormType;
use Tallyst\FormBuilder\Form\Type\FormDefinitionType;
use Tallyst\FormBuilder\Payment\MerchantOfRecordInterface;
use Tallyst\FormBuilder\Payment\PaymentProcessorRegistry;
use Tallyst\FormBuilder\Repository\FormDefinitionRepository;

/**
 * Admin builder UI. Lives under /admin (so the ROLE_ADMIN access control applies)
 * and is linked from the dashboard "Moduli" section via the module's
 * getAdminMenuItems(). The Symfony Form component is used ONLY here (admin), never
 * for the rendered end-user form.
 */
// dashboardControllerFqcn makes EasyAdmin build its AdminContext for these routes,
// so the templates can extend @EasyAdmin/page/content.html.twig and get the admin
// shell (sidebar + header). This string is the app's dashboard — the one explicit
// coupling a module needs to live inside the admin chrome.
#[Route('/admin/forms', defaults: ['dashboardControllerFqcn' => 'App\Controller\Admin\DashboardController'])]
#[IsGranted('ROLE_ADMIN')]
class FormBuilderController extends AbstractController
{
    public function __construct(
        private readonly FormDefinitionRepository $forms,
        private readonly SluggerInterface $slugger,
        private readonly TranslatorInterface $translator,
        private readonly PaymentProcessorRegistry $payments,
    ) {
    }

    #[Route('', name: 'form_builder_admin_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@FormBuilder/admin/index.html.twig', [
            'forms' => $this->forms->findBy([], ['id' => 'DESC']),
        ]);
    }

    /**
     * Faza 4: creating a form goes through a short WIZARD (Q1 messages/sells → Q2 physical/digital → Q3
     * self/MoR; physical never asks Q3). GET shows the wizard; POST maps the answers to a formType, creates
     * a DRAFT with that type, and hands off to the (type-aware) builder. Editing an EXISTING form is the
     * builder, never the wizard.
     */
    #[Route('/new', name: 'form_builder_admin_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('form_wizard', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $type = $this->wizardType($request);
            $definition = (new FormDefinition())
                ->setFormType($type)
                ->setName($this->translator->trans('admin.form.wizard.default_name', [], 'admin'));
            // Faza 5 K3: a MoR draft records WHICH provider (Q4, validated against registered MoR providers).
            if (FormType::DIGITAL_MOR === $type) {
                $definition->setMorProvider($this->wizardMorProvider($request));
            }
            $this->normalize($definition); // slug from the name + made unique
            $this->forms->save($definition);

            // Straight into the type-aware builder to fill in the details.
            return $this->redirectToRoute('form_builder_admin_edit', ['id' => $definition->getId()]);
        }

        return $this->render('@FormBuilder/admin/wizard.html.twig', [
            'mor_providers' => $this->morProviders(),
        ]);
    }

    /**
     * The AUTHORITATIVE mapping of the wizard answers to a FormType (server-side, so it holds even if JS
     * only drives the reveal). Physical never reaches Q3 — a physical product can't go through a MoR.
     * Anything unexpected falls back to MESSAGES (a harmless free draft).
     */
    private function wizardType(Request $request): FormType
    {
        $sells = 'sells' === $request->request->get('q1');
        $physical = 'physical' === $request->request->get('q2');
        $digital = 'digital' === $request->request->get('q2');
        $mor = 'mor' === $request->request->get('q3');

        return match (true) {
            $sells && $physical => FormType::PHYSICAL,
            $sells && $digital && $mor => FormType::DIGITAL_MOR,
            $sells && $digital => FormType::DIGITAL,
            default => FormType::MESSAGES,
        };
    }

    /**
     * The chosen MoR provider (Q4), validated against the REGISTERED MoR providers (same marker the resolver
     * uses). A forged / missing Q4 falls back to the first registered MoR provider so the draft stays valid
     * (the MorProviderMatchesType invariant). Never null in practice — Dodo is always registered.
     */
    private function wizardMorProvider(Request $request): ?string
    {
        $names = array_column($this->morProviders(), 'name');
        $q4 = (string) $request->request->get('q4');

        return in_array($q4, $names, true) ? $q4 : ($names[0] ?? null);
    }

    /**
     * Every REGISTERED Merchant-of-Record provider (the marker interface — the single source of truth),
     * with its display label + whether it's configured. Drives the wizard Q4 cards.
     *
     * @return list<array{name: string, label: string, configured: bool}>
     */
    private function morProviders(): array
    {
        $out = [];
        foreach ($this->payments->names() as $name) {
            $processor = $this->payments->get($name);
            if ($processor instanceof MerchantOfRecordInterface) {
                $out[] = ['name' => $name, 'label' => ucfirst($name), 'configured' => $processor->isConfigured()];
            }
        }

        return $out;
    }

    /**
     * Vet EVERY MoR sellable unit on a MoR form at save time (Faza 6 K3). Returns the FIRST problem found:
     *   ['status' => 'reject', 'label' => <row>]  → a recurring / usage-based / pay-what-you-want unit —
     *                                                Tallyst can't sell it → the whole save is rejected,
     *   ['status' => 'warn',   'label' => <row>]  → couldn't verify (provider unreachable) → save + flag,
     *   ['status' => 'ok']                        → all units are sellable (or there are none to check).
     * A unit already in the filtered list is sellable by construction (no re-fetch); only a MANUALLY-typed /
     * out-of-list id is verified. Resolved THROUGH MerchantOfRecordInterface (the form's morProvider), never
     * `instanceof DodoProcessor` — a non-MoR form has no provider → 'ok'. The CHECKOUT path is untouched.
     *
     * @return array{status: string, label?: string}
     */
    private function morUnitsStatus(FormDefinition $definition): array
    {
        $provider = $this->payments->merchantOfRecord($definition->getMorProvider());
        if (null === $provider) {
            return ['status' => 'ok'];
        }

        $listed = array_column($provider->listUnits(), 'id');
        $warn = null;
        foreach ($definition->getMorUnits() ?? [] as $unit) {
            $id = trim((string) ($unit['unitId'] ?? ''));
            if ('' === $id || in_array($id, $listed, true)) {
                continue; // empty (half-filled → validateMorUnits) or from the filtered list → sellable
            }
            $label = ('' !== trim((string) ($unit['label'] ?? ''))) ? $unit['label'] : $id;
            $verdict = $provider->isSellableUnit($id);
            if (false === $verdict) {
                return ['status' => 'reject', 'label' => $label]; // one bad unit fails the whole save
            }
            if (null === $verdict && null === $warn) {
                $warn = ['status' => 'warn', 'label' => $label];
            }
        }

        return $warn ?? ['status' => 'ok'];
    }

    /**
     * Faza 5 K7: the "refresh from Dodo" button on a Dodo MoR form. Re-fetches ONE product's current data
     * (GET /products/{id}) so the admin can pull an updated name / description / price / currency AFTER the
     * one-time prefill. READ-ONLY, on demand — never a live sync, never a checkout path. The JS compares
     * against the on-screen values, shows the diff and asks for confirmation before applying. JSON `status`:
     *   - ok        → live data (+ a `sellable`/`archived` flag so the JS can WARN if it turned into a
     *                 subscription / usage-based / pay-what-you-want / archived product),
     *   - not_found → the product no longer exists on Dodo,
     *   - error     → Dodo unreachable / unconfigured (no data lost, the JS just says so).
     */
    #[Route('/dodo-product-info', name: 'form_builder_admin_dodo_product', methods: ['GET'])]
    public function dodoProductInfo(Request $request): JsonResponse
    {
        $id = trim((string) $request->query->get('id', ''));
        if ('' === $id) {
            return $this->json(['status' => 'error']);
        }

        // Faza 6 K2: resolve the MoR provider from the ?provider= hint (the form's morProvider, sent by the
        // JS), else the first registered MoR provider (back-compat — today Dodo). Provider-agnostic; a second
        // MoR provider's fetchUnit() would answer here with no change.
        $provider = $this->payments->merchantOfRecord($request->query->get('provider'))
            ?? $this->payments->firstMerchantOfRecord();
        if (null === $provider) {
            return $this->json(['status' => 'error']);
        }

        $info = $provider->fetchUnit($id);
        if (null === $info) {
            return $this->json(['status' => 'error']);
        }
        if (false === ($info['found'] ?? false)) {
            return $this->json(['status' => 'not_found']);
        }

        $minor = $info['priceMinor'] ?? null;

        return $this->json([
            'status' => 'ok',
            'name' => $info['name'] ?? '',
            'description' => $info['description'] ?? '',
            'priceMajor' => null !== $minor ? number_format($minor / 100, 2, '.', '') : null,
            // Lowercase to match the form's currency <option> values (eur/usd/gbp); the JS uppercases for display.
            'currency' => null !== ($info['currency'] ?? null) ? strtolower((string) $info['currency']) : null,
            'taxInclusive' => $info['taxInclusive'] ?? null, // Faza 8: bool|null → the front's exclusive-tax note
            'pricingMode' => $info['pricingMode'] ?? null,   // Faza 8: string|null → localised-price wording
            'sellable' => (bool) ($info['sellable'] ?? false),
            'archived' => (bool) ($info['archived'] ?? false),
        ]);
    }

    /**
     * Resolve the MoR provider for a read-only builder endpoint — the ?provider= hint (the form's morProvider,
     * sent by the JS), else the first registered MoR provider (today Dodo). Provider-agnostic (Faza 7): a
     * second MoR provider answers here unchanged.
     */
    private function morProvider(Request $request): ?MerchantOfRecordInterface
    {
        return $this->payments->merchantOfRecord($request->query->get('provider'))
            ?? $this->payments->firstMerchantOfRecord();
    }

    /**
     * Faza 7: the "import from collection" picker source — the MoR provider's unit CONTAINERS (Dodo product
     * collections). READ-ONLY. JSON:
     *   - error → no MoR provider / the provider is unconfigured (`listContainers()` returns [] for BOTH
     *             unconfigured AND genuinely-none, so the isConfigured() check is what tells them apart),
     *   - ok    → `containers` (may be EMPTY → the provider simply has no collections; the UI hides import).
     */
    #[Route('/mor-containers', name: 'form_builder_admin_mor_containers', methods: ['GET'])]
    public function morContainers(Request $request): JsonResponse
    {
        $provider = $this->morProvider($request);
        if (null === $provider || !$provider->isConfigured()) {
            return $this->json(['status' => 'error']);
        }

        return $this->json([
            'status' => 'ok',
            'containers' => array_map(static fn (array $c): array => [
                'id' => (string) ($c['id'] ?? ''),
                'name' => (string) ($c['name'] ?? ''),
                'description' => $c['description'] ?? null,
                'productsCount' => $c['productsCount'] ?? null,
            ], $provider->listContainers()),
        ]);
    }

    /**
     * Faza 7: ONE container's data for import — its name/description (form prefill) + the sellable units +
     * the SKIPPED products (name + reason: inactive/recurring/usage_based/pay_what_you_want). READ-ONLY. JSON:
     *   - error → no provider / no id / unconfigured / not found / API error (`containerUnits()` returns null),
     *   - ok    → name/description + units (may be EMPTY = nothing sellable) + skipped.
     * The per-unit guard already ran in containerUnits(); the save-time morUnitsStatus is still the final gate.
     */
    #[Route('/mor-container-units', name: 'form_builder_admin_mor_container_units', methods: ['GET'])]
    public function morContainerUnits(Request $request): JsonResponse
    {
        $provider = $this->morProvider($request);
        $id = trim((string) $request->query->get('id', ''));
        if (null === $provider || '' === $id) {
            return $this->json(['status' => 'error']);
        }

        $data = $provider->containerUnits($id);
        if (null === $data) {
            return $this->json(['status' => 'error']); // unconfigured / not found / API error
        }

        return $this->json([
            'status' => 'ok',
            'name' => (string) ($data['name'] ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'units' => array_map(static function (array $u): array {
                $minor = $u['priceMinor'] ?? null;

                return [
                    'unitId' => (string) ($u['id'] ?? ''),
                    'name' => (string) ($u['name'] ?? ''),
                    'description' => $u['description'] ?? null,
                    'priceMajor' => null !== $minor ? number_format($minor / 100, 2, '.', '') : null,
                    // Lowercase to match the form's currency <option> values (eur/usd/gbp).
                    'currency' => null !== ($u['currency'] ?? null) ? strtolower((string) $u['currency']) : null,
                    'taxInclusive' => $u['taxInclusive'] ?? null, // Faza 8: bool|null
                    'pricingMode' => $u['pricingMode'] ?? null,   // Faza 8: string|null (localised pricing)
                ];
            }, $data['units']),
            'skipped' => array_map(static fn (array $s): array => [
                'name' => (string) ($s['name'] ?? ''),
                'reason' => (string) ($s['reason'] ?? ''),
            ], $data['skipped']),
        ]);
    }

    #[Route('/{id}/edit', name: 'form_builder_admin_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function editExisting(Request $request, FormDefinition $form): Response
    {
        return $this->edit($request, $form);
    }

    #[Route('/{id}/delete', name: 'form_builder_admin_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, FormDefinition $form): Response
    {
        if ($this->isCsrfTokenValid('delete_form_'.$form->getId(), (string) $request->request->get('_token'))) {
            $this->forms->remove($form);
            $this->addFlash('success', $this->translator->trans('admin.form.flash.deleted', [], 'admin'));
        }

        return $this->redirectToRoute('form_builder_admin_index');
    }

    private function edit(Request $request, FormDefinition $definition): Response
    {
        $form = $this->createForm(FormDefinitionType::class, $definition);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->normalize($definition);

            $duplicates = $this->duplicateKeys($definition);
            // Faza 6 K3: EVERY MoR sellable unit is vetted (per-unit) — a manually-typed id not in the
            // filtered list is verified; a recurring / usage-based / pay-what-you-want unit REJECTS the whole
            // save (the message names the offending row), an unverifiable one WARNS. (Faza 5 was one product.)
            $unitsStatus = $this->morUnitsStatus($definition);
            if ([] !== $duplicates) {
                $form->get('fields')->addError(new FormError(
                    $this->translator->trans('admin.form.builder.duplicate_keys', ['%keys%' => implode(', ', $duplicates)], 'admin'),
                ));
            } elseif ('reject' === $unitsStatus['status']) {
                $form->get('morUnits')->addError(new FormError(
                    $this->translator->trans('admin.form.builder.mor_unit_unsupported', ['%unit%' => $unitsStatus['label']], 'admin'),
                ));
            } else {
                if ('warn' === $unitsStatus['status']) {
                    $this->addFlash('warning', $this->translator->trans('admin.form.flash.dodo_unverified', ['%unit%' => $unitsStatus['label']], 'admin'));
                }
                $this->forms->save($definition);
                $this->addFlash('success', $this->translator->trans('admin.form.flash.saved', [], 'admin'));

                return $this->redirectToRoute('form_builder_admin_edit', ['id' => $definition->getId()]);
            }
        }

        return $this->render('@FormBuilder/admin/edit.html.twig', [
            'form' => $form->createView(),
            'definition' => $definition,
        ]);
    }

    /**
     * Fill in slug + field keys, keep slug unique, and re-sequence field positions
     * from their submitted order.
     */
    private function normalize(FormDefinition $definition): void
    {
        if ('' === trim($definition->getSlug())) {
            $definition->setSlug($this->slugify($definition->getName()));
        } elseif ($this->slugShouldFollowName($definition)) {
            // Faza 5 K6: a DRAFT still carrying the wizard's auto placeholder slug (untitled-form[-N])
            // follows the name once the admin gives it a real one. SAFEST rule — only auto-change a slug
            // that is (a) never public yet (draft) AND (b) demonstrably auto-generated (matches the
            // placeholder pattern), so a published form (live links / [form id=N] elsewhere) or a manually
            // typed slug is NEVER touched.
            $definition->setSlug($this->slugify($definition->getName()));
        }
        $definition->setSlug($this->uniqueSlug($definition));

        foreach ($definition->getFields() as $field) {
            if ('' === trim($field->getKey())) {
                $field->setKey($this->slugify($field->getLabel()));
            }
        }

        // Persisted positions reflect the order the admin arranged (drag/up-down).
        $fields = $definition->getFields()->toArray();
        usort($fields, static fn ($a, $b): int => $a->getPosition() <=> $b->getPosition());
        foreach ($fields as $index => $field) {
            $field->setPosition($index);
        }

        // Faza 6 K3 (transitional): the builder now writes morUnits, but the front + checkout still read the
        // legacy single dodoProductId (that moves to the unit list in Komad 4). So MIRROR the first unit's id
        // into dodoProductId — a single-unit MoR form then behaves IDENTICALLY to today (checkout charges that
        // product), and 0 units → null → the existing "product not linked" path. Removed once the checkout
        // reads sellableUnits() directly (K4). Only for MoR forms (a non-MoR form has no morUnits/dodoProductId).
        if ($definition->getFormType()->isMerchantOfRecord()) {
            $units = $definition->getMorUnits() ?? [];
            $definition->setDodoProductId($units[0]['unitId'] ?? null);
        }
    }

    /**
     * Should the slug be regenerated from the name? Only when the form is a DRAFT, the admin has given it a
     * real name (no longer the wizard default), and the current slug is STILL the auto placeholder
     * (`untitled-form` or `untitled-form-N`). A published form or a manual slug returns false.
     */
    private function slugShouldFollowName(FormDefinition $definition): bool
    {
        if ($definition->isPublished()) {
            return false; // never change a published form's slug (live links / shortcodes)
        }

        $defaultName = $this->translator->trans('admin.form.wizard.default_name', [], 'admin');
        if ('' === trim($definition->getName()) || $definition->getName() === $defaultName) {
            return false; // no real name yet
        }

        $placeholder = $this->slugify($defaultName);

        return 1 === preg_match('/^'.preg_quote($placeholder, '/').'(-\d+)?$/', $definition->getSlug());
    }

    /** @return string[] duplicated field keys */
    private function duplicateKeys(FormDefinition $definition): array
    {
        $seen = [];
        $duplicates = [];
        foreach ($definition->getFields() as $field) {
            $key = $field->getKey();
            if ('' === $key) {
                continue;
            }
            if (isset($seen[$key])) {
                $duplicates[$key] = true;
            }
            $seen[$key] = true;
        }

        return array_keys($duplicates);
    }

    private function slugify(string $value): string
    {
        $slug = $this->slugger->slug($value)->lower()->toString();

        return '' !== $slug ? $slug : 'polje';
    }

    private function uniqueSlug(FormDefinition $definition): string
    {
        $base = $this->slugify($definition->getSlug());
        $slug = $base;
        $suffix = 2;

        while (true) {
            $existing = $this->forms->findOneBy(['slug' => $slug]);
            if (null === $existing || $existing === $definition) {
                return $slug;
            }
            $slug = $base.'-'.$suffix++;
        }
    }
}
