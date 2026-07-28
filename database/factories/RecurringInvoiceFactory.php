<?php

namespace Database\Factories;

use App\Domain\Invoicing\Enums\RecurringDueDateRule;
use App\Domain\Invoicing\Enums\RecurringFrequency;
use App\Domain\Invoicing\Enums\RecurringInvoiceStatus;
use App\Domain\Invoicing\Enums\RecurringLimitType;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\RecurringInvoice;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringInvoice>
 */
class RecurringInvoiceFactory extends Factory
{
    protected $model = RecurringInvoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'client_id' => Client::factory(),
            'status' => RecurringInvoiceStatus::Active,
            'frequency' => RecurringFrequency::Monthly,
            'generate_on_day' => 1,
            'generate_on_last_day' => false,
            'limit_type' => RecurringLimitType::None,
            'generated_count' => 0,
            'next_run_date' => now()->toDateString(),
            'auto_send' => false,
            'period_offset_months' => 0,
            'due_date_rule' => RecurringDueDateRule::ClientTerms,
            'currency' => 'ZAR',
            'line_items' => [
                [
                    'description' => 'Rent for {{month_year}}',
                    'quantity' => 1,
                    'unit_price_cents' => 1000000,
                    'vat_rate' => 0.15,
                ],
            ],
        ];
    }
}
