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
use Tallyst\FormBuilder\Payment\DodoProcessor;
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
        private readonly DodoProcessor $dodo,
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
     * Vet the Dodo product on a Dodo MoR form at save time: 'ok' (nothing to check, or a sellable product),
     * 'reject' (a recurring / usage-based / pay-what-you-want product — Tallyst can't sell it), or 'warn'
     * (couldn't verify — Dodo unreachable — so allow the save but flag it). A product already in the filtered
     * list is sellable by construction (no re-fetch); only a MANUALLY-typed / out-of-list id is verified via
     * GET /products/{id}. The CHECKOUT path is untouched — this is a save-time gate only.
     */
    private function dodoProductStatus(FormDefinition $definition): string
    {
        if (FormType::DIGITAL_MOR !== $definition->getFormType()
            || $this->dodo->getName() !== $definition->getMorProvider()
            || null === ($id = $definition->getDodoProductId())) {
            return 'ok';
        }

        if (in_array($id, array_column($this->dodo->listProducts(), 'id'), true)) {
            return 'ok'; // from the already-filtered list → sellable
        }

        return match ($this->dodo->isSellableProduct($id)) {
            true => 'ok',
            false => 'reject',
            default => 'warn', // null → couldn't verify
        };
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

        $info = $this->dodo->fetchProductInfo($id);
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
            'sellable' => (bool) ($info['sellable'] ?? false),
            'archived' => (bool) ($info['archived'] ?? false),
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
            // Faza 5 K5: a manually-typed Dodo product id (not from the filtered list) is vetted here — a
            // recurring / usage-based / pay-what-you-want product is REJECTED; an unverifiable one WARNS.
            $productStatus = $this->dodoProductStatus($definition);
            if ([] !== $duplicates) {
                $form->get('fields')->addError(new FormError(
                    $this->translator->trans('admin.form.builder.duplicate_keys', ['%keys%' => implode(', ', $duplicates)], 'admin'),
                ));
            } elseif ('reject' === $productStatus) {
                $form->get('dodoProductId')->addError(new FormError(
                    $this->translator->trans('admin.form.builder.dodo_product_unsupported', [], 'admin'),
                ));
            } else {
                if ('warn' === $productStatus) {
                    $this->addFlash('warning', $this->translator->trans('admin.form.flash.dodo_unverified', [], 'admin'));
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
