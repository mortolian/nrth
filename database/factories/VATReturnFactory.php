<?php

namespace Database\Factories;

use App\Domain\Tax\Models\TaxPeriod;
use App\Domain\Tax\Models\VATReturn;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VATReturn>
 */
class VATReturnFactory extends Factory
{
    protected $model = VATReturn::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'tax_period_id' => TaxPeriod::factory(),
            'output_vat_cents' => 150_00,
            'input_vat_cents' => 50_00,
            'net_vat_cents' => 100_00,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
        ];
    }
}
