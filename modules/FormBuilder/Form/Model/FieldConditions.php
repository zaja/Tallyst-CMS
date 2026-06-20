<?php

namespace Tallyst\FormBuilder\Form\Model;

/**
 * Edit-time DTO mirroring the FormField.conditions JSON. Converted to/from the
 * stored array by ConditionsTransformer.
 */
class FieldConditions
{
    public string $action = 'show';
    public string $match = 'all';

    /** @var ConditionRule[] */
    public array $rules = [];
}
