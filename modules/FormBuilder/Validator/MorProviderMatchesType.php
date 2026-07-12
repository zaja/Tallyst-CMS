<?php

namespace Tallyst\FormBuilder\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Class-level invariant on FormDefinition (Faza 5): `morProvider` must MATCH the form's `formType`.
 *   - `digital_mor` → morProvider must be a REGISTERED Merchant-of-Record provider (non-null);
 *   - any other type → morProvider must be NULL.
 * Enforced server-side (the builder form validates the mapped entity); the wizard sets it, backfill sets it.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class MorProviderMatchesType extends Constraint
{
    /** digital_mor form whose morProvider is missing or not a registered MoR provider. */
    public string $mustBeMoRProvider = 'form.mor_provider_invalid';

    /** A non-MoR form that carries a morProvider (must be cleared). */
    public string $mustBeNull = 'form.mor_provider_on_non_mor';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
