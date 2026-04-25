<?php

namespace Database\Factories;

use App\Domain\Invoicing\Models\Client;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->company(),
            'email' => fake()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'vat_number' => fake()->optional()->numerify('VAT########'),
            'registration_number' => fake()->optional()->numerify('REG########'),
            'address' => [
                'line_1' => fake()->streetAddress(),
                'city' => fake()->city(),
                'country' => 'ZA',
            ],
            'currency' => 'ZAR',
            'payment_terms_days' => 30,
            'notes' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }
}
