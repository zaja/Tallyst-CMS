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
}
