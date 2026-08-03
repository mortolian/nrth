<?php

namespace Database\Factories;

use App\Domain\Vehicles\Models\Vehicle;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $make = fake()->randomElement(['Toyota', 'Volkswagen', 'Ford', 'Nissan', 'Hyundai']);
        $model = fake()->randomElement(['Hilux', 'Polo', 'Ranger', 'NP200', 'i20']);

        return [
            'team_id' => Team::factory(),
            'name' => $make.' '.$model,
            'make' => $make,
            'model' => $model,
            'year' => fake()->numberBetween(2015, 2026),
            'registration_number' => strtoupper(fake()->bothify('?? ## ?? GP')),
            'vin' => strtoupper(fake()->bothify('????#########???##')),
            'current_odometer_km' => fake()->randomFloat(1, 1000, 180000),
            'notes' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }
}
