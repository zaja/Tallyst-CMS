<?php

namespace App\Tests\FormBuilder;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\FormType;

/**
 * Faza 5 Komad 1 — the morProvider ↔ formType invariant (MorProviderMatchesType): a digital_mor form must
 * carry a REGISTERED MoR provider; any other type must carry none. Validated via the real validator service
 * (the constraint validator resolves "is a MoR provider" through the payment registry — 'dodo' is one,
 * 'stripe' is not). Also the setter's empty → null normalization.
 */
class FormMorProviderTest extends KernelTestCase
{
    public function testSetterNormalizesEmptyToNull(): void
    {
        self::assertNull((new FormDefinition())->setMorProvider('')->getMorProvider());
        self::assertNull((new FormDefinition())->setMorProvider('  ')->getMorProvider());
        self::assertSame('dodo', (new FormDefinition())->setMorProvider(' dodo ')->getMorProvider());
    }

    public function testDigitalMoRWithDodoIsValid(): void
    {
        self::assertCount(0, $this->violations($this->form(FormType::DIGITAL_MOR, 'dodo')));
    }

    public function testDigitalMoRWithoutProviderIsInvalid(): void
    {
        $v = $this->violations($this->form(FormType::DIGITAL_MOR, null));
        self::assertGreaterThan(0, $v->count(), 'a MoR form must carry a provider');
        self::assertSame('morProvider', $v->get(0)->getPropertyPath());
    }

    public function testDigitalMoRWithNonMoRProviderIsInvalid(): void
    {
        // 'stripe' is registered but is NOT a Merchant-of-Record provider.
        self::assertGreaterThan(0, $this->violations($this->form(FormType::DIGITAL_MOR, 'stripe'))->count());
    }

    public function testNonMoRFormWithProviderIsInvalid(): void
    {
        self::assertGreaterThan(0, $this->violations($this->form(FormType::PHYSICAL, 'dodo'))->count(), 'a non-MoR form can\'t carry a provider');
        self::assertGreaterThan(0, $this->violations($this->form(FormType::DIGITAL, 'dodo'))->count());
        self::assertGreaterThan(0, $this->violations($this->form(FormType::MESSAGES, 'dodo'))->count());
    }

    public function testNonMoRFormWithoutProviderIsValid(): void
    {
        self::assertCount(0, $this->violations($this->form(FormType::MESSAGES, null)));
        self::assertCount(0, $this->violations($this->form(FormType::PHYSICAL, null)));
        self::assertCount(0, $this->violations($this->form(FormType::DIGITAL, null)));
    }

    private function form(FormType $type, ?string $provider): FormDefinition
    {
        return (new FormDefinition())->setName('T')->setSlug('t-'.bin2hex(random_bytes(4)))
            ->setFormType($type)->setMorProvider($provider);
    }

    private function violations(FormDefinition $form)
    {
        self::bootKernel();

        // Validate ONLY the class-level MoR invariant (avoid unrelated field constraints noise).
        return static::getContainer()->get(ValidatorInterface::class)->validate($form, new \Tallyst\FormBuilder\Validator\MorProviderMatchesType());
    }
}
