<?php

namespace Tallyst\FormBuilder\Form\Model;

/**
 * Edit-time DTO for a single visibility rule. Persisted form is the JSON shape in
 * FormField.conditions (see ConditionsTransformer) — this class only exists for the
 * admin Symfony form.
 */
class ConditionRule
{
    public ?string $field = null;
    public ?string $operator = null;
    public ?string $value = null;
}
