/*
 * Node test: runs the JS evaluator against the SHARED fixture, mirroring the PHP
 * test. Run with:  node modules/FormBuilder/tests/js/condition_evaluator.test.mjs
 * Exits non-zero on any mismatch.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { isVisible, visibleKeys } from '../../assets/condition_evaluator.js';

const here = dirname(fileURLToPath(import.meta.url));
const fixture = JSON.parse(readFileSync(join(here, '../fixtures/condition_cases.json'), 'utf8'));

let passed = 0;

for (const c of fixture.conditionCases) {
    assert.equal(isVisible(c.conditions, c.values), c.expectedVisible, `conditionCase: ${c.name}`);
    passed++;
}

for (const c of fixture.fixedPointCases) {
    const actual = [...visibleKeys(c.fields, c.values)].sort();
    const expected = [...c.expectedVisibleKeys].sort();
    assert.deepEqual(actual, expected, `fixedPointCase: ${c.name}`);
    passed++;
}

console.log(`OK: ${passed} condition cases passed (JS).`);
