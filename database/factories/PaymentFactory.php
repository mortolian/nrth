<?php

namespace Database\Factories;

use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\Payment;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'invoice_id' => Invoice::factory(),
            'amount_cents' => 100_00,
            'currency' => 'ZAR',
            'payment_date' => now()->toDateString(),
            'method' => PaymentMethod::Eft,
            'reference' => fake()->optional()->bothify('PAY-####'),
            'notes' => fake()->optional()->sentence(),
            'transaction_id' => null,
        ];
    }
}
