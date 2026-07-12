<?php

namespace App\Tests\FormBuilder;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\FormType;

/**
 * Faza 4 Komad 1: the explicit FormType enum semantics + the FormDefinition field. These map the type to
 * the two booleans the rest of the code reads (isProduct / isMerchantOfRecord) — the contract KOMAD 2 will
 * wire the guessing methods onto. ⚠ In KOMAD 1 the field is stored but NOT yet consumed.
 */
class FormTypeTest extends TestCase
{
    /**
     * @return array<string, array{FormType, bool, bool, bool}>  [type, isProduct, isMoR, isPhysical]
     */
    public static function typeMatrix(): array
    {
        return [
            'messages'    => [FormType::MESSAGES,    false, false, false],
            'physical'    => [FormType::PHYSICAL,    true,  false, true],
            'digital'     => [FormType::DIGITAL,     true,  false, false],
            'digital_mor' => [FormType::DIGITAL_MOR, true,  true,  false],
        ];
    }

    #[DataProvider('typeMatrix')]
    public function testEnumSemantics(FormType $type, bool $isProduct, bool $isMoR, bool $isPhysical): void
    {
        self::assertSame($isProduct, $type->isProduct());
        self::assertSame($isMoR, $type->isMerchantOfRecord());
        self::assertSame($isPhysical, $type->isPhysical());
    }

    public function testBackingValues(): void
    {
        self::assertSame('messages', FormType::MESSAGES->value);
        self::assertSame('physical', FormType::PHYSICAL->value);
        self::assertSame('digital', FormType::DIGITAL->value);
        self::assertSame('digital_mor', FormType::DIGITAL_MOR->value);
    }

    public function testEntityDefaultsToMessages(): void
    {
        // A bare form is inert (a free message form) — so KOMAD 1 changes nothing for a newly-built form.
        self::assertSame(FormType::MESSAGES, (new FormDefinition())->getFormType());
    }

    public function testEntityGetterSetterAndHelpers(): void
    {
        $form = (new FormDefinition())->setFormType(FormType::DIGITAL_MOR);
        self::assertSame(FormType::DIGITAL_MOR, $form->getFormType());
        self::assertTrue($form->isProductType());
        self::assertTrue($form->isMerchantOfRecordType());

        $form->setFormType(FormType::MESSAGES);
        self::assertFalse($form->isProductType());
        self::assertFalse($form->isMerchantOfRecordType());

        $form->setFormType(FormType::PHYSICAL);
        self::assertTrue($form->isProductType());
        self::assertFalse($form->isMerchantOfRecordType());
    }
}
