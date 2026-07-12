<?php

namespace Tallyst\FormBuilder\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
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

            $definition = (new FormDefinition())
                ->setFormType($this->wizardType($request))
                ->setName($this->translator->trans('admin.form.wizard.default_name', [], 'admin'));
            $this->normalize($definition); // slug from the name + made unique
            $this->forms->save($definition);

            // Straight into the type-aware builder to fill in the details.
            return $this->redirectToRoute('form_builder_admin_edit', ['id' => $definition->getId()]);
        }

        return $this->render('@FormBuilder/admin/wizard.html.twig', [
            'dodo_configured' => $this->dodo->isConfigured(),
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
            if ([] !== $duplicates) {
                $form->get('fields')->addError(new FormError(
                    $this->translator->trans('admin.form.builder.duplicate_keys', ['%keys%' => implode(', ', $duplicates)], 'admin'),
                ));
            } else {
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
