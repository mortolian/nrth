<?php

namespace Database\Factories;

use App\Domain\Tax\Enums\TaxPeriodStatus;
use App\Domain\Tax\Enums\TaxPeriodType;
use App\Domain\Tax\Models\TaxPeriod;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxPeriod>
 */
class TaxPeriodFactory extends Factory
{
    protected $model = TaxPeriod::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = Carbon::now()->startOfMonth();
        $end = $start->copy()->addMonthsNoOverflow(2)->subDay();

        return [
            'team_id' => Team::factory(),
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'type' => TaxPeriodType::VAT,
            'status' => TaxPeriodStatus::Open,
            'due_date' => $end->copy()->addDays(25)->toDateString(),
            'submitted_at' => null,
            'notes' => null,
        ];
    }
}
