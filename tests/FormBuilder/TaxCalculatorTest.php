<?php

namespace App\Tests\FormBuilder;

use App\Settings\SettingsManager;
use PHPUnit\Framework\TestCase;
use Tallyst\FormBuilder\Service\TaxCalculator;

/**
 * Inclusive tax math: tax = gross - net, so net + tax always sum back to the gross (no cent drift).
 */
class TaxCalculatorTest extends TestCase
{
    /**
     * @param array<string, mixed> $settings
     */
    private function calc(array $settings): TaxCalculator
    {
        $manager = $this->createStub(SettingsManager::class);
        $manager->method('get')->willReturnCallback(static fn (string $k) => $settings[$k] ?? null);

        return new TaxCalculator($manager);
    }

    public function testInclusiveSplit(): void
    {
        $b = $this->calc(['tax_rate' => '25'])->breakdown(4900);
        self::assertSame(3920, $b['net']);
        self::assertSame(980, $b['tax']);
        self::assertSame(4900, $b['net'] + $b['tax']);
    }

    public function testRoundingClosesOnOddAmount(): void
    {
        $b = $this->calc(['tax_rate' => '25'])->breakdown(4999);
        self::assertSame(4999, $b['net'] + $b['tax'], 'net + tax must always equal gross');
        self::assertSame(3999, $b['net']);
        self::assertSame(1000, $b['tax']);
    }

    public function testZeroRateMeansNoTax(): void
    {
        $b = $this->calc(['tax_rate' => '0'])->breakdown(4900);
        self::assertSame(4900, $b['net']);
        self::assertSame(0, $b['tax']);
    }

    public function testIsEnabledAndRateParsing(): void
    {
        self::assertTrue($this->calc(['tax_enabled' => true])->isEnabled());
        self::assertFalse($this->calc([])->isEnabled());
        self::assertSame(25.5, $this->calc(['tax_rate' => '25,5'])->rate(), 'comma decimal parses');
        self::assertSame('PDV', $this->calc([])->name());
    }
}
