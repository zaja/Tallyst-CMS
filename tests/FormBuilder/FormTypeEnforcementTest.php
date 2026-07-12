<?php

namespace App\Tests\FormBuilder;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\FormType;
use Tallyst\FormBuilder\Form\Type\FormDefinitionType;

/**
 * Faza 4 KOMAD 5 — the type is LOCKED in the builder except the digital ↔ digital_mor pair (a wizard
 * decision, not freely changed). The builder's formType select offers ONLY the allowed transitions.
 * (offeredMethods never offering Dodo on a non-MoR form → FormPaymentResolverTest; shipping/countries
 * only on a physical form → FormSubmitShippingTest; the hard no-Dodo-method guard → FormBuilderBuilderRenderTest.)
 */
class FormTypeEnforcementTest extends KernelTestCase
{
    public function testMessagesAndPhysicalAreLocked(): void
    {
        self::assertSame(['messages'], $this->typeChoices(FormType::MESSAGES));
        self::assertSame(['physical'], $this->typeChoices(FormType::PHYSICAL));
    }

    public function testDigitalPairIsSwitchable(): void
    {
        self::assertSame(['digital', 'digital_mor'], $this->typeChoices(FormType::DIGITAL));
        self::assertSame(['digital', 'digital_mor'], $this->typeChoices(FormType::DIGITAL_MOR));
    }

    /** @return string[] the option VALUES (enum backing strings) the formType select offers for this type */
    private function typeChoices(FormType $type): array
    {
        self::bootKernel();
        $view = static::getContainer()->get(FormFactoryInterface::class)
            ->create(FormDefinitionType::class, (new FormDefinition())->setFormType($type))
            ->get('formType')->createView();

        return array_map(static fn ($c) => $c->value, $view->vars['choices']);
    }
}
