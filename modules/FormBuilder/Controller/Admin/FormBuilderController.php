<?php

namespace Tallyst\FormBuilder\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Form\Type\FormDefinitionType;
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
class FormBuilderController extends AbstractController
{
    public function __construct(
        private readonly FormDefinitionRepository $forms,
        private readonly SluggerInterface $slugger,
    ) {
    }

    #[Route('', name: 'form_builder_admin_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@FormBuilder/admin/index.html.twig', [
            'forms' => $this->forms->findBy([], ['id' => 'DESC']),
        ]);
    }

    #[Route('/new', name: 'form_builder_admin_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return $this->edit($request, new FormDefinition());
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
            $this->addFlash('success', 'Forma je obrisana.');
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
                    'Ključevi polja moraju biti jedinstveni unutar forme. Duplikati: '.implode(', ', $duplicates),
                ));
            } else {
                $this->forms->save($definition);
                $this->addFlash('success', 'Forma je spremljena.');

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
