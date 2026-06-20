<?php

namespace Tallyst\FormBuilder\Condition;

/**
 * Evaluates field visibility rules. This is the SERVER half of the single source
 * of truth — the JS module assets/condition_evaluator.js mirrors it exactly, and
 * BOTH are tested against modules/FormBuilder/tests/fixtures/condition_cases.json.
 *
 * Canonical operator semantics (must match JS byte-for-byte in behaviour):
 *  - toStr: null->"" , bool->("1"|"") , int/float->PHP/JS string cast , string->self.
 *  - equals / not_equals / contains are STRING based. So "5" equals 5 is TRUE
 *    (both cast to "5"). For array actuals (multi-value), equals/contains mean
 *    "the value is a member of the array".
 *  - contains on a scalar is a case-SENSITIVE substring test.
 *  - gt / lt are NUMERIC only: both sides must match /^-?\d+(\.\d+)?$/, else FALSE.
 *  - empty: null, "", [], or boolean false. ("0" is NOT empty.)
 *  - unknown operator => FALSE.
 *
 * Visibility: rules combine with match=all (AND) or any (OR). action=show makes the
 * field visible when the combined result is true; action=hide inverts it. No rules
 * => always visible.
 */
class ConditionEvaluator
{
    private const NUMERIC = '/^-?\d+(\.\d+)?$/';

    /**
     * @param array<string, mixed> $rule   {field, operator, value}
     * @param array<string, mixed> $values current field values keyed by field key
     */
    public function isRuleMet(array $rule, array $values): bool
    {
        $field = (string) ($rule['field'] ?? '');
        $operator = (string) ($rule['operator'] ?? '');
        $expected = (string) ($rule['value'] ?? '');
        $actual = $values[$field] ?? null;

        return match ($operator) {
            'empty' => self::isEmpty($actual),
            'not_empty' => !self::isEmpty($actual),
            'equals' => self::eq($actual, $expected),
            'not_equals' => !self::eq($actual, $expected),
            'contains' => self::contains($actual, $expected),
            'gt' => self::numericCompare($actual, $expected, 'gt'),
            'lt' => self::numericCompare($actual, $expected, 'lt'),
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $conditions {action, match, rules}
     * @param array<string, mixed> $values
     */
    public function isVisible(array $conditions, array $values): bool
    {
        $rules = $conditions['rules'] ?? [];
        if (!is_array($rules) || 0 === count($rules)) {
            return true;
        }

        $action = $conditions['action'] ?? 'show';
        $match = $conditions['match'] ?? 'all';

        $results = [];
        foreach ($rules as $rule) {
            $results[] = is_array($rule) ? $this->isRuleMet($rule, $values) : false;
        }

        $combined = 'any' === $match
            ? in_array(true, $results, true)
            : !in_array(false, $results, true);

        return 'hide' === $action ? !$combined : $combined;
    }

    /**
     * Cycle-guarded fixed point: hidden fields get their value cleared, which can
     * cascade to dependents. Clearing is monotonic so it converges; the iteration
     * cap (number of fields) is a safety net against pathological configs.
     *
     * @param list<array{key: string, conditions: array<string, mixed>}> $fields
     * @param array<string, mixed>                                        $values
     *
     * @return list<string> keys of the visible fields
     */
    public function visibleKeys(array $fields, array $values): array
    {
        $working = $values;
        $count = count($fields);
        $visibility = [];

        for ($iteration = 0; $iteration <= $count; ++$iteration) {
            $visibility = [];
            foreach ($fields as $field) {
                $visibility[$field['key']] = $this->isVisible($field['conditions'] ?? [], $working);
            }

            $changed = false;
            foreach ($fields as $field) {
                $key = $field['key'];
                if (!$visibility[$key] && !self::isEmpty($working[$key] ?? null)) {
                    $working[$key] = '';
                    $changed = true;
                }
            }

            if (!$changed) {
                break;
            }
        }

        $visible = [];
        foreach ($visibility as $key => $isVisible) {
            if ($isVisible) {
                $visible[] = $key;
            }
        }

        return $visible;
    }

    private static function eq(mixed $actual, string $expected): bool
    {
        if (is_array($actual)) {
            foreach ($actual as $element) {
                if (self::toStr($element) === $expected) {
                    return true;
                }
            }

            return false;
        }

        return self::toStr($actual) === $expected;
    }

    private static function contains(mixed $actual, string $expected): bool
    {
        if (is_array($actual)) {
            foreach ($actual as $element) {
                if (self::toStr($element) === $expected) {
                    return true;
                }
            }

            return false;
        }

        return str_contains(self::toStr($actual), $expected);
    }

    private static function numericCompare(mixed $actual, string $expected, string $op): bool
    {
        $a = self::asNumber($actual);
        $b = self::asNumber($expected);
        if (null === $a || null === $b) {
            return false;
        }

        return 'gt' === $op ? $a > $b : $a < $b;
    }

    private static function asNumber(mixed $v): ?float
    {
        if (null === $v || is_bool($v) || is_array($v)) {
            return null;
        }

        $s = trim(self::toStr($v));

        return 1 === preg_match(self::NUMERIC, $s) ? (float) $s : null;
    }

    private static function isEmpty(mixed $v): bool
    {
        if (null === $v) {
            return true;
        }
        if (is_bool($v)) {
            return false === $v;
        }
        if (is_array($v)) {
            return 0 === count($v);
        }
        if (is_string($v)) {
            return '' === $v;
        }

        return false;
    }

    private static function toStr(mixed $v): string
    {
        if (null === $v) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? '1' : '';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        if (is_string($v)) {
            return $v;
        }

        return '';
    }
}
