<?php

namespace Database\Factories;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $issueDate = Carbon::now()->startOfDay();
        $dueDate = $issueDate->copy()->addDays(30);

        return [
            'team_id' => Team::factory(),
            'client_id' => Client::factory(),
            'status' => InvoiceStatus::Draft,
            'number' => 'INV-'.$issueDate->year.'-'.$this->faker->unique()->numerify('####'),
            'reference' => fake()->optional()->bothify('REF-####'),
            'issue_date' => $issueDate->toDateString(),
            'due_date' => $dueDate->toDateString(),
            'subtotal_cents' => 100_00,
            'vat_amount_cents' => 15_00,
            'total_cents' => 115_00,
            'amount_paid_cents' => 0,
            'currency' => 'ZAR',
            'notes' => fake()->optional()->sentence(),
            'footer' => fake()->optional()->sentence(),
            'sent_at' => null,
            'viewed_at' => null,
            'paid_at' => null,
            'voided_at' => null,
            'transaction_id' => null,
        ];
    }
}
