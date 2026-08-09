<?php

namespace App\Customer;

use App\Entity\Customer;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Contributes one block to the customer's account page.
 *
 * ⚠ This exists so Core can own the account page without knowing what is ON it. Orders live in
 * FormBuilder, and Core must not read them (modules depend on Core, never the reverse — Media is
 * the single exception). FormBuilder contributes its purchases block through this tag, the same
 * way it contributes a dashboard widget, a settings section and its own mail types.
 *
 * It is also the seam the roadmap needs: support tickets and subscriptions become another block
 * each, contributed by whatever owns them, without Core learning about their tables.
 */
#[AutoconfigureTag('app.customer_account_section')]
interface CustomerAccountSectionInterface
{
    /** Lower sorts first. Purchases are 10; later blocks slot around that. */
    public function getPosition(): int;

    public function getTemplate(): string;

    /**
     * Data for the template, for THIS customer only.
     *
     * @return array<string, mixed>
     */
    public function getData(Customer $customer): array;
}
