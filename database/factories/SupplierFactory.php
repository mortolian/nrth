<?php

namespace Database\Factories;

use App\Domain\Accounting\Models\Supplier;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->company(),
            'contact_name' => fake()->optional()->name(),
            'email' => fake()->optional()->companyEmail(),
            'phone' => fake()->optional()->phoneNumber(),
            'vat_number' => null,
            'registration_number' => fake()->optional()->numerify('##########'),
            'address' => [
                'street' => fake()->streetAddress(),
                'city' => fake()->city(),
                'province' => fake()->state(),
                'postal_code' => fake()->postcode(),
                'country' => 'South Africa',
            ],
            'notes' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }
}
