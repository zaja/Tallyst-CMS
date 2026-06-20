<?php

namespace App\Tests\FormBuilder;

use PHPUnit\Framework\TestCase;
use Tallyst\FormBuilder\Condition\ConditionEvaluator;

/**
 * Runs the PHP evaluator against the SHARED fixture. The Node test
 * (modules/FormBuilder/tests/js/condition_evaluator.test.mjs) runs the JS mirror
 * against the same file — they must stay in agreement.
 */
class ConditionEvaluatorTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../../modules/FormBuilder/tests/fixtures/condition_cases.json';

    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(self::FIXTURE), true, 512, \JSON_THROW_ON_ERROR);
    }

    public function testConditionCases(): void
    {
        $evaluator = new ConditionEvaluator();
        $cases = self::fixture()['conditionCases'];
        self::assertNotEmpty($cases);

        foreach ($cases as $case) {
            self::assertSame(
                $case['expectedVisible'],
                $evaluator->isVisible($case['conditions'], $case['values']),
                'conditionCase: '.$case['name'],
            );
        }
    }

    public function testFixedPointCases(): void
    {
        $evaluator = new ConditionEvaluator();
        $cases = self::fixture()['fixedPointCases'];
        self::assertNotEmpty($cases);

        foreach ($cases as $case) {
            $actual = $evaluator->visibleKeys($case['fields'], $case['values']);
            sort($actual);
            $expected = $case['expectedVisibleKeys'];
            sort($expected);

            self::assertSame($expected, $actual, 'fixedPointCase: '.$case['name']);
        }
    }
}
