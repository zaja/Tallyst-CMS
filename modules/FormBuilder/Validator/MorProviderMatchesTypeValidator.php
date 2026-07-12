<?php

namespace Tallyst\FormBuilder\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedValueException;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\FormType;
use Tallyst\FormBuilder\Payment\MerchantOfRecordInterface;
use Tallyst\FormBuilder\Payment\PaymentProcessorRegistry;

/**
 * Validates {@see MorProviderMatchesType}: a `digital_mor` form must carry a REGISTERED Merchant-of-Record
 * provider; any other type must carry none. "Registered MoR provider" = the marker interface (same single
 * source of truth the resolver + submit use), so this can't drift from what actually IS a MoR provider.
 */
class MorProviderMatchesTypeValidator extends ConstraintValidator
{
    public function __construct(private readonly PaymentProcessorRegistry $payments)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof MorProviderMatchesType) {
            throw new UnexpectedValueException($constraint, MorProviderMatchesType::class);
        }
        if (!$value instanceof FormDefinition) {
            return;
        }

        $provider = $value->getMorProvider();

        if (FormType::DIGITAL_MOR === $value->getFormType()) {
            if (null === $provider || !$this->isMerchantOfRecordProvider($provider)) {
                $this->context->buildViolation($constraint->mustBeMoRProvider)
                    ->atPath('morProvider')
                    ->addViolation();
            }

            return;
        }

        if (null !== $provider) {
            $this->context->buildViolation($constraint->mustBeNull)
                ->atPath('morProvider')
                ->addViolation();
        }
    }

    private function isMerchantOfRecordProvider(string $name): bool
    {
        return in_array($name, $this->payments->names(), true)
            && $this->payments->get($name) instanceof MerchantOfRecordInterface;
    }
}
