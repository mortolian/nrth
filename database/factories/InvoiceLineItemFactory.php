<?php

namespace Database\Factories;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceLineItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceLineItem>
 */
class InvoiceLineItemFactory extends Factory
{
    protected $model = InvoiceLineItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = 2;
        $unitPrice = 50_00;
        $subtotal = $quantity * $unitPrice;
        $vatAmount = (int) round($subtotal * 0.15);

        return [
            'invoice_id' => Invoice::factory(),
            'description' => fake()->sentence(),
            'quantity' => $quantity,
            'unit_price_cents' => $unitPrice,
            'vat_rate' => 0.1500,
            'vat_amount_cents' => $vatAmount,
            'total_cents' => $subtotal + $vatAmount,
            'sort_order' => 0,
        ];
    }
}
