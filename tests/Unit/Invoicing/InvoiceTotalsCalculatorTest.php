<?php

namespace Tests\Unit\Invoicing;

use App\Domain\Invoicing\Services\InvoiceTotalsCalculator;
use PHPUnit\Framework\TestCase;

class InvoiceTotalsCalculatorTest extends TestCase
{
    public function test_percent_line_discount_reduces_base_before_vat(): void
    {
        $calc = new InvoiceTotalsCalculator;
        $result = $calc->calculate([
            [
                'quantity' => 1,
                'unit_price_cents' => 10000,
                'vat_rate' => 0.15,
                'discount_type' => 'percent',
                'discount_percent' => 10,
            ],
        ]);

        $this->assertSame(1000, $result['lines'][0]['line_discount_cents']);
        $this->assertSame(9000, $result['lines'][0]['taxed_base_cents']);
        $this->assertSame(1350, $result['lines'][0]['vat_amount_cents']);
        $this->assertSame(10350, $result['total_cents']);
        $this->assertSame(1000, $result['discount_total_cents']);
    }

    public function test_fixed_line_discount_cannot_exceed_base(): void
    {
        $calc = new InvoiceTotalsCalculator;
        $result = $calc->calculate([
            [
                'quantity' => 1,
                'unit_price_cents' => 5000,
                'vat_rate' => 0,
                'discount_type' => 'fixed',
                'discount_cents' => 99999,
            ],
        ]);

        $this->assertSame(5000, $result['lines'][0]['line_discount_cents']);
        $this->assertSame(0, $result['total_cents']);
    }

    public function test_document_percent_discount_allocates_across_mixed_vat_rates(): void
    {
        $calc = new InvoiceTotalsCalculator;
        $result = $calc->calculate(
            [
                ['quantity' => 1, 'unit_price_cents' => 10000, 'vat_rate' => 0.15],
                ['quantity' => 1, 'unit_price_cents' => 10000, 'vat_rate' => 0.0],
            ],
            'percent',
            10,
            null,
        );

        $this->assertSame(2000, $result['discount_total_cents']);
        $this->assertSame(18000, $result['subtotal_cents']);
        $this->assertSame(9000, $result['lines'][0]['taxed_base_cents']);
        $this->assertSame(9000, $result['lines'][1]['taxed_base_cents']);
        $this->assertSame(1350, $result['lines'][0]['vat_amount_cents']);
        $this->assertSame(0, $result['lines'][1]['vat_amount_cents']);
        $this->assertSame(19350, $result['total_cents']);
    }

    public function test_line_and_document_discounts_stack(): void
    {
        $calc = new InvoiceTotalsCalculator;
        $result = $calc->calculate(
            [
                [
                    'quantity' => 1,
                    'unit_price_cents' => 10000,
                    'vat_rate' => 0,
                    'discount_type' => 'fixed',
                    'discount_cents' => 1000,
                ],
            ],
            'percent',
            10,
            null,
        );

        // Line discount 1000 → exclusive 9000; document 10% of 9000 = 900; taxable 8100
        $this->assertSame(1900, $result['discount_total_cents']);
        $this->assertSame(8100, $result['subtotal_cents']);
    }
}
