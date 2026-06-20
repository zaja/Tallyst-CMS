<?php

namespace Tallyst\FormBuilder\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Tallyst\FormBuilder\Condition\ConditionEvaluator;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\FormField;
use Tallyst\FormBuilder\Entity\FormSubmission;
use Tallyst\FormBuilder\Form\FormSchemaFactory;
use Tallyst\FormBuilder\Repository\FormDefinitionRepository;
use Tallyst\FormBuilder\Repository\FormSubmissionRepository;

/**
 * Public form submission endpoint. Two-segment path (/form/...) so the /{slug}
 * catch-all never matches it. Post/Redirect/Get on both success and failure to
 * avoid double-submit on refresh.
 *
 * NOTE (pass 2 / later): this endpoint is public and unauthenticated, so it will
 * attract spam — add rate limiting + a honeypot before going to production.
 */
class FormSubmitController extends AbstractController
{
    public function __construct(
        private readonly FormDefinitionRepository $forms,
        private readonly FormSubmissionRepository $submissions,
        private readonly FormSchemaFactory $schemas,
        private readonly ConditionEvaluator $evaluator,
    ) {
    }

    #[Route('/form/{id}/submit', name: 'form_builder_submit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function submit(int $id, Request $request): Response
    {
        $form = $this->forms->findPublished($id);
        if (null === $form) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('form_submit_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        /** @var FormField[] $fields */
        $fields = $form->getFields()->toArray();

        // Raw values for every field (controlling values included).
        $raw = [];
        foreach ($fields as $field) {
            $raw[$field->getKey()] = $this->readValue($request, $field);
        }

        // Condition-aware: only fields the rules keep visible are validated/saved.
        $visible = array_flip($this->evaluator->visibleKeys($this->schemas->condition($form), $raw));

        $errors = [];
        $data = [];
        foreach ($fields as $field) {
            $key = $field->getKey();
            if (!isset($visible[$key])) {
                continue; // hidden by conditions — not required, not validated, value dropped
            }

            $value = $raw[$key];
            $error = $this->validateField($field, $value);
            if (null !== $error) {
                $errors[$key] = $error;
                continue;
            }

            $data[$key] = $value;
        }

        $return = $this->safeReturn((string) $request->request->get('_return', '/'));

        if ([] !== $errors) {
            $bag = $request->getSession()->getFlashBag();
            $bag->add('fb_errors_'.$id, $errors);
            $bag->add('fb_old_'.$id, $raw);

            return $this->redirect($return);
        }

        $submission = (new FormSubmission())
            ->setForm($form)
            ->setData($data)
            ->setIpAddress($request->getClientIp())
            ->setUserAgent(mb_substr((string) $request->headers->get('User-Agent', ''), 0, 1000));
        $this->submissions->save($submission);

        $separator = str_contains($return, '?') ? '&' : '?';

        return $this->redirect($return.$separator.'fb_success='.$id);
    }

    private function readValue(Request $request, FormField $field): mixed
    {
        if (FormField::TYPE_CHECKBOX === $field->getType()) {
            return $request->request->has($field->getKey()) ? '1' : false;
        }

        return (string) $request->request->get($field->getKey(), '');
    }

    private function validateField(FormField $field, mixed $value): ?string
    {
        $blank = '' === $value || false === $value || null === $value;

        if ($field->isRequired() && $blank) {
            return 'Ovo polje je obavezno.';
        }

        if ($blank) {
            return null;
        }

        return match ($field->getType()) {
            FormField::TYPE_EMAIL => false === filter_var((string) $value, \FILTER_VALIDATE_EMAIL)
                ? 'Unesite ispravan e-mail.' : null,
            FormField::TYPE_NUMBER => !is_numeric((string) $value)
                ? 'Unesite broj.' : null,
            FormField::TYPE_SELECT, FormField::TYPE_RADIO => !in_array((string) $value, $field->getOptions(), true)
                ? 'Neispravan odabir.' : null,
            default => null,
        };
    }

    /** Only allow same-site path redirects (no open redirect). */
    private function safeReturn(string $return): string
    {
        return str_starts_with($return, '/') && !str_starts_with($return, '//') ? $return : '/';
    }
}
