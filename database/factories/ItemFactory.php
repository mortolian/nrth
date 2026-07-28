<?php

namespace Database\Factories;

use App\Domain\Invoicing\Models\Item;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    protected $model = Item::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'unit' => fake()->optional()->randomElement(['each', 'hour', 'day', 'month']),
            'unit_price_cents' => fake()->numberBetween(10000, 500000),
            'default_vat_rate' => 0.1500,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
