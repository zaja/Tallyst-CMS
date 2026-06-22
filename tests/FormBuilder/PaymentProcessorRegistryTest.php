<?php

namespace App\Tests\FormBuilder;

use PHPUnit\Framework\TestCase;
use Tallyst\FormBuilder\Payment\PaymentProcessorInterface;
use Tallyst\FormBuilder\Payment\PaymentProcessorRegistry;

/**
 * availableFor() = configured providers ∩ the form's allowed list (null/empty = all) — the single
 * source the form render + the submit both use to offer/validate a payment method.
 */
class PaymentProcessorRegistryTest extends TestCase
{
    private function processor(string $name, bool $configured): PaymentProcessorInterface
    {
        $p = $this->createStub(PaymentProcessorInterface::class);
        $p->method('getName')->willReturn($name);
        $p->method('isConfigured')->willReturn($configured);

        return $p;
    }

    private function registry(bool $stripe, bool $paypal): PaymentProcessorRegistry
    {
        return new PaymentProcessorRegistry([
            $this->processor('stripe', $stripe),
            $this->processor('paypal', $paypal),
        ]);
    }

    public function testBothConfiguredNoLimitGivesBoth(): void
    {
        self::assertSame(['stripe', 'paypal'], $this->registry(true, true)->availableFor(null));
        self::assertSame(['stripe', 'paypal'], $this->registry(true, true)->availableFor([]));
    }

    public function testLimitNarrowsToOne(): void
    {
        self::assertSame(['paypal'], $this->registry(true, true)->availableFor(['paypal']));
    }

    public function testUnconfiguredIsExcluded(): void
    {
        self::assertSame(['stripe'], $this->registry(true, false)->availableFor(null));
    }

    public function testLimitToUnconfiguredGivesEmpty(): void
    {
        self::assertSame([], $this->registry(true, false)->availableFor(['paypal']));
    }

    public function testNamesListsAllKnown(): void
    {
        self::assertSame(['stripe', 'paypal'], $this->registry(false, false)->names());
    }
}
