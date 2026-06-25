<?php

namespace App\Controller\Admin;

use App\Email\EmailRenderer;
use App\Email\EmailTypeRegistry;
use App\Entity\EmailTemplate;
use App\Repository\EmailTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Basic editor for the editable email templates (PASS 1 — plain textarea body; the rich
 * editor + "insert tag" UI is PASS 2). The list is driven from the EmailTypeRegistry (every
 * type visible before any edit); each edit pre-fills from the DB override ?? the registry
 * default and upserts an EmailTemplate row. Admin-only; lives in the EA shell via the
 * dashboardControllerFqcn route default.
 */
#[Route('/admin/email', defaults: ['dashboardControllerFqcn' => 'App\Controller\Admin\DashboardController'])]
#[IsGranted('ROLE_ADMIN')]
class EmailTemplateController extends AbstractController
{
    public function __construct(
        private readonly EmailTypeRegistry $registry,
        private readonly EmailTemplateRepository $templates,
        private readonly EmailRenderer $renderer,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'admin_email_templates', methods: ['GET'])]
    public function index(): Response
    {
        $rows = [];
        foreach ($this->registry->all() as $type) {
            $override = $this->templates->findOneByIdentifier($type->key);
            $rows[] = [
                'type' => $type,
                'overridden' => null !== $override,
                'enabled' => $type->canDisable ? ($override?->isEnabled() ?? true) : true,
            ];
        }

        return $this->render('admin/email_templates/index.html.twig', ['rows' => $rows]);
    }

    #[Route('/{key}', name: 'admin_email_template_edit', methods: ['GET', 'POST'])]
    public function edit(string $key, Request $request): Response
    {
        $type = $this->registry->get($key);
        if (null === $type) {
            throw $this->createNotFoundException(\sprintf('Unknown email type "%s".', $key));
        }

        $template = $this->templates->findOneByIdentifier($key) ?? new EmailTemplate($key);

        $data = [
            'subject' => '' !== $template->getSubject() ? $template->getSubject() : $type->defaultSubject,
            'body' => '' !== $template->getBody() ? $template->getBody() : $type->defaultBody,
            'enabled' => $type->canDisable ? $template->isEnabled() : true,
        ];

        $builder = $this->createFormBuilder($data)
            ->add('subject', TextType::class, ['label' => $this->translator->trans('admin.email.edit.field.subject', [], 'admin')])
            ->add('body', TextareaType::class, ['label' => $this->translator->trans('admin.email.edit.field.body', [], 'admin'), 'attr' => ['rows' => 16]]);
        if ($type->canDisable) {
            $builder->add('enabled', CheckboxType::class, ['label' => $this->translator->trans('admin.email.edit.field.enabled', [], 'admin'), 'required' => false]);
        }
        $form = $builder->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $values = $form->getData();
            $missing = $this->renderer->bodyMissingRequiredTags($key, (string) $values['body']);
            if ([] !== $missing) {
                $this->addFlash('danger', $this->translator->trans('admin.flash.email_required_tags', ['%tags%' => implode(', ', array_map(static fn (string $t): string => '{'.$t.'}', $missing))], 'admin'));
            } else {
                $template->setIdentifier($key)
                    ->setSubject((string) $values['subject'])
                    ->setBody((string) $values['body'])
                    // Non-disableable types (e.g. reset) are always enabled, regardless of input.
                    ->setEnabled($type->canDisable ? (bool) ($values['enabled'] ?? false) : true);

                $this->em->persist($template);
                $this->em->flush();
                $this->addFlash('success', $this->translator->trans('admin.flash.email_template_saved', [], 'admin'));

                return $this->redirectToRoute('admin_email_template_edit', ['key' => $key]);
            }
        }

        return $this->render('admin/email_templates/edit.html.twig', [
            'form' => $form->createView(),
            'type' => $type,
        ]);
    }
}
