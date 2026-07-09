<?php

namespace App\Tests\FormBuilder;

use PHPUnit\Framework\TestCase;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\Order;
use Tallyst\FormBuilder\Payment\MerchantOfRecordInterface;
use Tallyst\FormBuilder\Payment\PaymentProcessorInterface;
use Tallyst\FormBuilder\Payment\PaymentProcessorRegistry;
use Tallyst\FormBuilder\Payment\WebhookResult;
use Tallyst\FormBuilder\Service\FormPaymentResolver;

/**
 * The single source of truth for "is this form MoR / what does it offer". Pure unit test with fake
 * processors (a configured MoR "dodo" + a configured non-MoR "stripe"), so it's deterministic and
 * independent of env/settings.
 */
class FormPaymentResolverTest extends TestCase
{
    private function registry(bool $dodoConfigured = true, bool $stripeConfigured = true): PaymentProcessorRegistry
    {
        return new PaymentProcessorRegistry([
            $this->processor('stripe', $stripeConfigured, false),
            $this->processor('dodo', $dodoConfigured, true),
        ]);
    }

    private function resolver(bool $dodoConfigured = true, bool $stripeConfigured = true): FormPaymentResolver
    {
        return new FormPaymentResolver($this->registry($dodoConfigured, $stripeConfigured));
    }

    private function form(?string $dodoProductId, ?array $allowed): FormDefinition
    {
        $f = new FormDefinition();
        $f->setPriceMinor(4900);
        $f->setDodoProductId($dodoProductId);
        $f->setAllowedPaymentMethods($allowed);

        return $f;
    }

    // --- isMerchantOfRecordForm -------------------------------------------------------------------

    public function testDodoProductIdMakesItMoR(): void
    {
        self::assertTrue($this->resolver()->isMerchantOfRecordForm($this->form('prod_1', null)));
        self::assertTrue($this->resolver()->isMerchantOfRecordForm($this->form('prod_1', ['stripe']))); // even with a stray non-MoR
    }

    public function testMoRMethodMakesItMoR(): void
    {
        self::assertTrue($this->resolver()->isMerchantOfRecordForm($this->form(null, ['dodo'])));
    }

    public function testPureStripeIsNotMoR(): void
    {
        self::assertFalse($this->resolver()->isMerchantOfRecordForm($this->form(null, ['stripe'])));
    }

    public function testEmptyNoProductIsNotMoR(): void
    {
        // The known edge: empty + both configured, no product → NOT MoR (no signal) → offers both.
        self::assertFalse($this->resolver()->isMerchantOfRecordForm($this->form(null, [])));
    }

    // --- offeredMethods ---------------------------------------------------------------------------

    public function testMoRFormOffersOnlyDodo(): void
    {
        self::assertSame(['dodo'], $this->resolver()->offeredMethods($this->form('prod_1', null)));
        self::assertSame(['dodo'], $this->resolver()->offeredMethods($this->form('prod_1', ['stripe', 'paypal'])));
        self::assertSame(['dodo'], $this->resolver()->offeredMethods($this->form(null, ['dodo'])));
    }

    public function testMoRFormWithUnconfiguredDodoOffersNothing(): void
    {
        self::assertSame([], $this->resolver(dodoConfigured: false)->offeredMethods($this->form('prod_1', null)));
    }

    public function testPureStripeOffersStripe(): void
    {
        self::assertSame(['stripe'], $this->resolver()->offeredMethods($this->form(null, ['stripe'])));
    }

    public function testEmptyBothConfiguredOffersBoth(): void
    {
        // Not a MoR form → availableFor(empty) = all configured (the documented backlog edge).
        self::assertEqualsCanonicalizing(['stripe', 'dodo'], $this->resolver()->offeredMethods($this->form(null, [])));
    }

    private function processor(string $name, bool $configured, bool $mor): PaymentProcessorInterface
    {
        if ($mor) {
            return new class($name, $configured) implements PaymentProcessorInterface, MerchantOfRecordInterface {
                public function __construct(private string $n, private bool $c)
                {
                }

                public function getName(): string
                {
                    return $this->n;
                }

                public function isConfigured(): bool
                {
                    return $this->c;
                }

                public function getMode(): string
                {
                    return $this->c ? 'test' : 'unconfigured';
                }

                public function createCheckout(Order $order, string $successUrl, string $cancelUrl): string
                {
                    return '';
                }

                public function finalizeReturn(Order $order): void
                {
                }

                public function parseSignedWebhook(string $payload, array $headers): WebhookResult
                {
                    return new WebhookResult('', null, null, false, null);
                }

                public function refund(Order $order): void
                {
                }

                public function getWebhookEvents(): array
                {
                    return [];
                }

                public function dashboardUrl(Order $order): ?string
                {
                    return null;
                }
            };
        }

        return new class($name, $configured) implements PaymentProcessorInterface {
            public function __construct(private string $n, private bool $c)
            {
            }

            public function getName(): string
            {
                return $this->n;
            }

            public function isConfigured(): bool
            {
                return $this->c;
            }

            public function getMode(): string
            {
                return $this->c ? 'test' : 'unconfigured';
            }

            public function createCheckout(Order $order, string $successUrl, string $cancelUrl): string
            {
                return '';
            }

            public function finalizeReturn(Order $order): void
            {
            }

            public function parseSignedWebhook(string $payload, array $headers): WebhookResult
            {
                return new WebhookResult('', null, null, false, null);
            }

            public function refund(Order $order): void
            {
            }

            public function getWebhookEvents(): array
            {
                return [];
            }

            public function dashboardUrl(Order $order): ?string
            {
                return null;
            }
        };
    }
}
