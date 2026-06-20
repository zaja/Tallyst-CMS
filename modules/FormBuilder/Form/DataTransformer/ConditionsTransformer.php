<?php

namespace Tallyst\FormBuilder\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;
use Tallyst\FormBuilder\Form\Model\ConditionRule;
use Tallyst\FormBuilder\Form\Model\FieldConditions;

/**
 * Bridges FormField.conditions (stored JSON array — the single source of truth) and
 * the editable FieldConditions DTO used by the admin form. Incomplete rules (no
 * field/operator) are dropped; no rules => empty array (field always visible).
 *
 * @implements DataTransformerInterface<array<string, mixed>, FieldConditions>
 */
class ConditionsTransformer implements DataTransformerInterface
{
    public function transform(mixed $value): FieldConditions
    {
        $dto = new FieldConditions();
        if (!is_array($value)) {
            return $dto;
        }

        $action = $value['action'] ?? 'show';
        $dto->action = \in_array($action, ['show', 'hide'], true) ? $action : 'show';

        $match = $value['match'] ?? 'all';
        $dto->match = \in_array($match, ['all', 'any'], true) ? $match : 'all';

        foreach ($value['rules'] ?? [] as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $rule = new ConditionRule();
            $rule->field = $raw['field'] ?? null;
            $rule->operator = $raw['operator'] ?? null;
            $rule->value = isset($raw['value']) ? (string) $raw['value'] : null;
            $dto->rules[] = $rule;
        }

        return $dto;
    }

    public function reverseTransform(mixed $value): array
    {
        if (!$value instanceof FieldConditions) {
            return [];
        }

        $rules = [];
        foreach ($value->rules as $rule) {
            if (!$rule instanceof ConditionRule) {
                continue;
            }
            if (null === $rule->field || '' === $rule->field || null === $rule->operator || '' === $rule->operator) {
                continue; // skip incomplete rows
            }
            $rules[] = [
                'field' => $rule->field,
                'operator' => $rule->operator,
                'value' => (string) ($rule->value ?? ''),
            ];
        }

        if ([] === $rules) {
            return []; // no rules => always visible
        }

        return [
            'action' => $value->action ?: 'show',
            'match' => $value->match ?: 'all',
            'rules' => $rules,
        ];
    }
}
