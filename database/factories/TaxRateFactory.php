<?php

namespace Database\Factories;

use App\Domain\Tax\Models\TaxRate;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxRate>
 */
class TaxRateFactory extends Factory
{
    protected $model = TaxRate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => 'VAT 15%',
            'rate' => 0.1500,
            'rate_percent' => 15.00,
            'code' => 'VAT15',
            'is_default' => true,
            'is_exempt' => false,
            'is_active' => true,
        ];
    }
}
