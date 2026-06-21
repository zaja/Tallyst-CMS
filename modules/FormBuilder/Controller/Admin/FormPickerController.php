<?php

namespace Tallyst\FormBuilder\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Repository\FormDefinitionRepository;

/**
 * JSON feed for the editor's "Ubaci formu" picker. NOT an EA-shell page (no
 * dashboardControllerFqcn default) — it returns JSON. It powers content editing (inserting
 * [form id=N] into a Page/Post), so it MUST stay ROLE_EDITOR — NOT ROLE_ADMIN — or editors
 * can't embed forms. Lists all forms (with status) so drafts can be embedded too; the front
 * still only renders published ones (FormShortcode::findPublished).
 */
#[Route('/admin/forms-list')]
#[IsGranted('ROLE_EDITOR')]
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
