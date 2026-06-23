<?php

namespace App\Tests\FormBuilder;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\WorkflowInterface;
use Tallyst\FormBuilder\Controller\Admin\OrderCrudController;
use Tallyst\FormBuilder\Entity\FormSubmission;
use Tallyst\FormBuilder\Entity\Order;
use Tallyst\FormBuilder\Payment\PaymentProcessorRegistry;
use Tallyst\FormBuilder\Repository\OrderRepository;
use Tallyst\FormBuilder\Service\OrderMailer;

/**
 * The accountant CSV must carry the buyer's form data (for invoicing), on one line, and a value with a
 * comma must not break the row (RFC CSV quoting via fputcsv).
 */
class OrderCsvExportTest extends TestCase
{
    private function csvFor(Order $order): string
    {
        $orders = $this->createStub(OrderRepository::class);
        $orders->method('findAllOrderedByIdDesc')->willReturn([$order]);

        $controller = new OrderCrudController(
            $this->createStub(WorkflowInterface::class),
            $this->createStub(OrderMailer::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(PaymentProcessorRegistry::class),
            $orders,
        );

        ob_start();
        $controller->exportCsv()->sendContent();

        return (string) ob_get_clean();
    }

    public function testExportIncludesFlattenedCustomerDataWithSafeQuoting(): void
    {
        $submission = (new FormSubmission())->setData([
            'ime' => 'Goran Zajec',
            'tvrtka' => 'Sve je dobro, j.d.o.o.',
        ]);
        $order = (new Order())
            ->setSubmission($submission)
            ->setProvider('stripe')
            ->setPaymentMode('test')
            ->setAmountMinor(4900)
            ->setCurrency('eur');

        $csv = $this->csvFor($order);

        self::assertStringContainsString('Podaci kupca', $csv, 'header column present');
        self::assertStringContainsString('Mod', $csv, 'mode column present');
        // Newlines flattened to "; " AND the comma-containing value is RFC-quoted (one cell, not split).
        self::assertStringContainsString('"ime: Goran Zajec; tvrtka: Sve je dobro, j.d.o.o."', $csv);
        // The data stays on the single data row (header + one row → exactly one newline after each).
        self::assertSame(1, substr_count(trim($csv), "\n"), 'no extra rows — the comma value did not break the row');
    }

    public function testExportNullSafeWithoutSubmission(): void
    {
        $order = (new Order())->setProvider('paypal')->setAmountMinor(4900)->setCurrency('eur');

        $csv = $this->csvFor($order);

        self::assertStringContainsString('Podaci kupca', $csv);
        self::assertSame(1, substr_count(trim($csv), "\n"), 'one data row even with no submission');
    }
}
