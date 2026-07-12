<?php

namespace App\Tests\FormBuilder;

use PHPUnit\Framework\TestCase;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\FormType;
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
 *
 * Faza 4 KOMAD 2: the FORM-level MoR question is now the EXPLICIT formType (DIGITAL_MOR), NOT the old
 * guess (Dodo product / a MoR method). offeredMethods keeps its behaviour: a MoR form offers only the
 * configured MoR provider(s); a non-MoR form offers configured ∩ allowed.
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

    private function form(FormType $type, ?array $allowed = null): FormDefinition
    {
        $f = new FormDefinition();
        $f->setFormType($type);
        $f->setPriceMinor(4900);
        $f->setAllowedPaymentMethods($allowed);

        return $f;
    }

    // --- isMerchantOfRecordForm (now reads formType) ----------------------------------------------

    public function testMoRTypeMakesItMoR(): void
    {
        self::assertTrue($this->resolver()->isMerchantOfRecordForm($this->form(FormType::DIGITAL_MOR)));
        // A stray non-MoR method in the allow-list doesn't change the type-driven answer.
        self::assertTrue($this->resolver()->isMerchantOfRecordForm($this->form(FormType::DIGITAL_MOR, ['stripe'])));
    }

    public function testNonMoRTypesAreNotMoR(): void
    {
        self::assertFalse($this->resolver()->isMerchantOfRecordForm($this->form(FormType::DIGITAL, ['stripe'])));
        self::assertFalse($this->resolver()->isMerchantOfRecordForm($this->form(FormType::PHYSICAL, ['stripe'])));
        self::assertFalse($this->resolver()->isMerchantOfRecordForm($this->form(FormType::MESSAGES)));
    }

    // --- offeredMethods ---------------------------------------------------------------------------

    public function testMoRFormOffersOnlyDodo(): void
    {
        self::assertSame(['dodo'], $this->resolver()->offeredMethods($this->form(FormType::DIGITAL_MOR)));
        // MoR is MoR-only even if the allow-list carries non-MoR methods.
        self::assertSame(['dodo'], $this->resolver()->offeredMethods($this->form(FormType::DIGITAL_MOR, ['stripe', 'paypal'])));
    }

    public function testMoRFormWithUnconfiguredDodoOffersNothing(): void
    {
        self::assertSame([], $this->resolver(dodoConfigured: false)->offeredMethods($this->form(FormType::DIGITAL_MOR)));
    }

    public function testPureStripeOffersStripe(): void
    {
        self::assertSame(['stripe'], $this->resolver()->offeredMethods($this->form(FormType::DIGITAL, ['stripe'])));
    }

    public function testNonMoRFormNeverOffersDodoEvenWhenEmpty(): void
    {
        // Faza 4 K5: a non-MoR form with an empty allow-list offers the configured SELF-BILLED providers
        // only — never Dodo (a MoR provider), which is reached solely via the digital_mor type.
        self::assertSame(['stripe'], $this->resolver()->offeredMethods($this->form(FormType::DIGITAL, [])));
        self::assertSame(['stripe'], $this->resolver()->offeredMethods($this->form(FormType::PHYSICAL, [])));
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
