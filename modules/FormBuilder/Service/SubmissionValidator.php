<?php

namespace Tallyst\FormBuilder\Service;

use Tallyst\FormBuilder\Condition\ConditionEvaluator;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\FormField;
use Tallyst\FormBuilder\Form\FormSchemaFactory;

/**
 * Server-side validation of a submitted form. The condition evaluator is the source of
 * truth for visibility: a field hidden by its display conditions is NOT required, NOT
 * validated, and its value is NOT stored. Visibility is a cascading fixed point — a field
 * whose condition depends on an already-hidden field is hidden too (see ConditionEvaluator).
 * Extracted from FormSubmitController so the conditional-required rule is unit-testable.
 */
class SubmissionValidator
{
    public function __construct(
        private readonly FormSchemaFactory $schemas,
        private readonly ConditionEvaluator $evaluator,
    ) {
    }

    /**
     * @param array<string, mixed> $raw all field values keyed by field key
     *
     * @return array{errors: array<string, string>, data: array<string, mixed>} `data` holds
     *                                                                           only the visible, valid values
     */
    public function validate(FormDefinition $form, array $raw): array
    {
        $visible = array_flip($this->evaluator->visibleKeys($this->schemas->condition($form), $raw));

        $errors = [];
        $data = [];
        foreach ($form->getFields() as $field) {
            $key = $field->getKey();
            if (!isset($visible[$key])) {
                continue; // hidden by conditions — not required, not validated, dropped
            }

            $value = $raw[$key] ?? '';
            $error = $this->validateField($field, $value);
            if (null !== $error) {
                $errors[$key] = $error;
                continue;
            }

            $data[$key] = $value;
        }

        return ['errors' => $errors, 'data' => $data];
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
}
