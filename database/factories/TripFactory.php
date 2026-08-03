<?php

namespace Database\Factories;

use App\Domain\Vehicles\Enums\TripPurpose;
use App\Domain\Vehicles\Models\Trip;
use App\Domain\Vehicles\Models\Vehicle;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Trip>
 */
class TripFactory extends Factory
{
    protected $model = Trip::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $started = fake()->dateTimeBetween('-6 months', 'now');
        $durationSeconds = fake()->numberBetween(180, 7200);
        $ended = (clone $started)->modify("+{$durationSeconds} seconds");
        $distance = fake()->randomFloat(1, 1, 250);

        return [
            'team_id' => Team::factory(),
            'vehicle_id' => Vehicle::factory(),
            'trip_date' => $started->format('Y-m-d'),
            'started_at' => $started,
            'ended_at' => $ended,
            'duration_seconds' => $durationSeconds,
            'distance_km' => $distance,
            'purpose' => fake()->randomElement([TripPurpose::Business, TripPurpose::Private]),
            'start_odometer_km' => null,
            'end_odometer_km' => null,
            'from_location' => fake()->optional()->city(),
            'to_location' => fake()->optional()->city(),
            'start_latitude' => fake()->optional(0.7)->latitude(-35, -22),
            'start_longitude' => fake()->optional(0.7)->longitude(16, 33),
            'end_latitude' => fake()->optional(0.7)->latitude(-35, -22),
            'end_longitude' => fake()->optional(0.7)->longitude(16, 33),
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function forVehicle(Vehicle $vehicle): static
    {
        return $this->state(fn () => [
            'team_id' => $vehicle->team_id,
            'vehicle_id' => $vehicle->id,
        ]);
    }

    public function business(): static
    {
        return $this->state(fn () => ['purpose' => TripPurpose::Business]);
    }

    public function private(): static
    {
        return $this->state(fn () => ['purpose' => TripPurpose::Private]);
    }
}
