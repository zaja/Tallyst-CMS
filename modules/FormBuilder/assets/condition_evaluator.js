/*
 * CLIENT half of the field-visibility single source of truth. This MUST mirror
 * modules/FormBuilder/Condition/ConditionEvaluator.php exactly. Both are tested
 * against modules/FormBuilder/tests/fixtures/condition_cases.json — keep them in
 * lock-step. See the PHP class docblock for the canonical operator semantics.
 *
 * Pure (no DOM): imported by the Stimulus controller AND by the Node test.
 */

const NUMERIC = /^-?\d+(\.\d+)?$/;

function toStr(v) {
    if (v === null || v === undefined) return '';
    if (typeof v === 'boolean') return v ? '1' : '';
    if (typeof v === 'number') return String(v);
    if (typeof v === 'string') return v;
    return '';
}

function isEmpty(v) {
    if (v === null || v === undefined) return true;
    if (typeof v === 'boolean') return v === false;
    if (Array.isArray(v)) return v.length === 0;
    if (typeof v === 'string') return v === '';
    return false;
}

function asNumber(v) {
    if (v === null || v === undefined || typeof v === 'boolean' || Array.isArray(v)) {
        return null;
    }
    const s = toStr(v).trim();
    return NUMERIC.test(s) ? parseFloat(s) : null;
}

function eq(actual, expected) {
    if (Array.isArray(actual)) {
        return actual.some((el) => toStr(el) === expected);
    }
    return toStr(actual) === expected;
}

function contains(actual, expected) {
    if (Array.isArray(actual)) {
        return actual.some((el) => toStr(el) === expected);
    }
    return toStr(actual).includes(expected);
}

export function isRuleMet(rule, values) {
    const field = String(rule.field ?? '');
    const operator = String(rule.operator ?? '');
    const expected = String(rule.value ?? '');
    const actual = Object.prototype.hasOwnProperty.call(values, field) ? values[field] : null;

    switch (operator) {
        case 'empty':
            return isEmpty(actual);
        case 'not_empty':
            return !isEmpty(actual);
        case 'equals':
            return eq(actual, expected);
        case 'not_equals':
            return !eq(actual, expected);
        case 'contains':
            return contains(actual, expected);
        case 'gt': {
            const a = asNumber(actual);
            const b = asNumber(expected);
            return a !== null && b !== null && a > b;
        }
        case 'lt': {
            const a = asNumber(actual);
            const b = asNumber(expected);
            return a !== null && b !== null && a < b;
        }
        default:
            return false;
    }
}

export function isVisible(conditions, values) {
    const rules = (conditions && conditions.rules) || [];
    if (!Array.isArray(rules) || rules.length === 0) {
        return true;
    }

    const action = (conditions && conditions.action) || 'show';
    const match = (conditions && conditions.match) || 'all';

    const results = rules.map((rule) => isRuleMet(rule, values));
    const combined = match === 'any' ? results.some(Boolean) : results.every(Boolean);

    return action === 'hide' ? !combined : combined;
}

/**
 * Cycle-guarded fixed point. fields: [{ key, conditions }]. Returns the list of
 * visible field keys. Clearing hidden values is monotonic, so it converges; the
 * iteration cap (field count) guards against pathological configs.
 */
export function visibleKeys(fields, values) {
    const working = { ...values };
    const count = fields.length;
    let visibility = {};

    for (let iteration = 0; iteration <= count; iteration++) {
        visibility = {};
        for (const field of fields) {
            visibility[field.key] = isVisible(field.conditions || {}, working);
        }

        let changed = false;
        for (const field of fields) {
            const key = field.key;
            if (!visibility[key] && !isEmpty(working[key] ?? null)) {
                working[key] = '';
                changed = true;
            }
        }

        if (!changed) break;
    }

    return fields.map((f) => f.key).filter((k) => visibility[k]);
}
