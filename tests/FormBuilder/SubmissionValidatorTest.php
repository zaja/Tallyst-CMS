<?php

namespace App\Tests\FormBuilder;

use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tallyst\FormBuilder\Condition\ConditionEvaluator;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\FormField;
use Tallyst\FormBuilder\Form\FormSchemaFactory;
use Tallyst\FormBuilder\Service\SubmissionValidator;

/**
 * Locks the conditional-required rule: a field hidden by its display conditions is never
 * required (nor validated, nor stored) — including the CHAINED case where a field is hidden
 * because the field its condition depends on is itself hidden (fixed-point cascade).
 */
class SubmissionValidatorTest extends TestCase
{
    private function validator(): SubmissionValidator
    {
        // Error TEXT isn't asserted (only which keys error), so a passthrough translator stub suffices.
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new SubmissionValidator(new FormSchemaFactory(), new ConditionEvaluator(), $translator);
    }

    private function field(string $key, string $type, bool $required, array $conditions = []): FormField
    {
        return (new FormField())->setKey($key)->setLabel($key)->setType($type)->setRequired($required)->setConditions($conditions);
    }

    /** show this field only when $depKey == $value */
    private function showIf(string $depKey, string $value): array
    {
        return ['action' => 'show', 'match' => 'all', 'rules' => [['field' => $depKey, 'operator' => 'equals', 'value' => $value]]];
    }

    private function form(FormField ...$fields): FormDefinition
    {
        $form = new FormDefinition();
        foreach ($fields as $field) {
            $form->addField($field);
        }

        return $form;
    }

    public function testHiddenRequiredFieldDoesNotBlockSubmit(): void
    {
        $form = $this->form(
            $this->field('a', FormField::TYPE_TEXT, false),
            $this->field('b', FormField::TYPE_TEXT, true, $this->showIf('a', 'yes')),
        );

        // a != yes => b is hidden => its "required" must not apply.
        $result = $this->validator()->validate($form, ['a' => 'no', 'b' => '']);

        self::assertSame([], $result['errors']);
        self::assertSame(['a' => 'no'], $result['data'], 'hidden field b is dropped, not stored');
    }

    public function testVisibleRequiredEmptyFieldBlocksSubmit(): void
    {
        $form = $this->form(
            $this->field('a', FormField::TYPE_TEXT, false),
            $this->field('b', FormField::TYPE_TEXT, true, $this->showIf('a', 'yes')),
        );

        // a == yes => b is visible => empty required b is an error.
        $result = $this->validator()->validate($form, ['a' => 'yes', 'b' => '']);

        self::assertArrayHasKey('b', $result['errors']);
    }

    public function testVisibleRequiredFilledFieldPasses(): void
    {
        $form = $this->form(
            $this->field('a', FormField::TYPE_TEXT, false),
            $this->field('b', FormField::TYPE_TEXT, true, $this->showIf('a', 'yes')),
        );

        $result = $this->validator()->validate($form, ['a' => 'yes', 'b' => 'hello']);

        self::assertSame([], $result['errors']);
        self::assertSame(['a' => 'yes', 'b' => 'hello'], $result['data']);
    }

    /**
     * CHAINED: c is required and "show if b == go", but b is "show if a == yes". With a != yes,
     * b hides → its value is cleared → c's condition (b == go) is now false → c hides too. The
     * required c must NOT block submit. This is the fixed-point cascade that a single-level test
     * would miss.
     */
    public function testChainedHiddenRequiredFieldDoesNotBlockSubmit(): void
    {
        $form = $this->form(
            $this->field('a', FormField::TYPE_TEXT, false),
            $this->field('b', FormField::TYPE_TEXT, true, $this->showIf('a', 'yes')),
            $this->field('c', FormField::TYPE_TEXT, true, $this->showIf('b', 'go')),
        );

        // b is submitted as "go", but b is itself hidden (a != yes) → cleared → c hides too.
        $result = $this->validator()->validate($form, ['a' => 'no', 'b' => 'go', 'c' => '']);

        self::assertSame([], $result['errors'], 'chained hidden required field must not block');
        self::assertSame(['a' => 'no'], $result['data'], 'both b and c are dropped');
    }

    public function testTypeValidationStillAppliesToVisibleFields(): void
    {
        $form = $this->form($this->field('email', FormField::TYPE_EMAIL, true));

        $result = $this->validator()->validate($form, ['email' => 'not-an-email']);

        self::assertArrayHasKey('email', $result['errors']);
    }
}
