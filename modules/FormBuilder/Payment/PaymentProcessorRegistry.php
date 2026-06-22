<?php

namespace Tallyst\FormBuilder\Payment;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Resolves a payment processor by name (Order::getProvider()). Adding PayPal in
 * pass 2b means just implementing PaymentProcessorInterface — it registers here
 * automatically.
 */
class PaymentProcessorRegistry
{
    /** @var array<string, PaymentProcessorInterface> */
    private array $processors = [];

    /**
     * @param iterable<PaymentProcessorInterface> $processors
     */
    public function __construct(
        #[AutowireIterator('app.payment_processor')]
        iterable $processors,
    ) {
        foreach ($processors as $processor) {
            $this->processors[$processor->getName()] = $processor;
        }
    }

    public function get(string $name): PaymentProcessorInterface
    {
        return $this->processors[$name]
            ?? throw new \InvalidArgumentException(\sprintf('Unknown payment provider "%s".', $name));
    }

    /**
     * All known provider names (configured or not) — the choice list for the per-product limit.
     *
     * @return string[]
     */
    public function names(): array
    {
        return array_keys($this->processors);
    }

    /**
     * Providers actually usable for a form: CONFIGURED (has credentials) ∩ ALLOWED (the form's
     * limit; null/empty = all). Order follows registration. The single source for both the form
     * render (offer a choice) and the submit (validate the chosen method).
     *
     * @param string[]|null $allowed
     *
     * @return string[]
     */
    public function availableFor(?array $allowed = null): array
    {
        $allowed = $allowed ?: null; // empty array means "no limit", same as null
        $names = [];
        foreach ($this->processors as $name => $processor) {
            if (null !== $allowed && !in_array($name, $allowed, true)) {
                continue;
            }
            if ($processor->isConfigured()) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
