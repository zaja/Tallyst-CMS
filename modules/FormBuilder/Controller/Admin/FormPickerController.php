<?php

namespace Tallyst\FormBuilder\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Repository\FormDefinitionRepository;

/**
 * JSON feed for the editor's "Ubaci formu" picker. NOT an EA-shell page (no
 * dashboardControllerFqcn default) — it returns JSON. Under ^/admin so ROLE_ADMIN
 * applies. Lists all forms (with status) so the admin can embed drafts too; the front
 * still only renders published ones (FormShortcode::findPublished).
 */
#[Route('/admin/forms-list')]
class FormPickerController extends AbstractController
{
    public function __construct(
        private readonly FormDefinitionRepository $forms,
    ) {
    }

    #[Route('', name: 'form_builder_picker_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $items = [];
        foreach ($this->forms->findBy([], ['name' => 'ASC']) as $form) {
            $items[] = [
                'id' => $form->getId(),
                'name' => $form->getName(),
                'published' => FormDefinition::STATUS_PUBLISHED === $form->getStatus(),
            ];
        }

        return $this->json(['items' => $items]);
    }
}
