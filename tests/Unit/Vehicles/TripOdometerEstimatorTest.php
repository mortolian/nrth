<?php

namespace Tests\Unit\Vehicles;

use App\Domain\Vehicles\Models\Trip;
use App\Domain\Vehicles\Models\Vehicle;
use App\Domain\Vehicles\Services\TripOdometerEstimator;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TripOdometerEstimatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_estimates_opening_and_closing_from_starting_odometer(): void
    {
        $team = Team::factory()->create();
        $vehicle = Vehicle::factory()->for($team)->create([
            'starting_odometer_km' => 1100,
        ]);

        $first = Trip::factory()->forVehicle($vehicle)->create([
            'trip_date' => '2026-01-01',
            'started_at' => '2026-01-01 08:00:00',
            'distance_km' => 40,
        ]);
        $second = Trip::factory()->forVehicle($vehicle)->create([
            'trip_date' => '2026-01-02',
            'started_at' => '2026-01-02 08:00:00',
            'distance_km' => 60,
        ]);

        $estimator = new TripOdometerEstimator;
        $estimates = $estimator->estimate(
            $vehicle,
            $estimator->chronological(collect([$second, $first])),
        );

        $this->assertSame(1100.0, $estimates[$first->id]['opening_km']);
        $this->assertSame(1140.0, $estimates[$first->id]['closing_km']);
        $this->assertSame(1140.0, $estimates[$second->id]['opening_km']);
        $this->assertSame(1200.0, $estimates[$second->id]['closing_km']);
    }

    public function test_returns_null_estimates_without_vehicle_odometer(): void
    {
        $team = Team::factory()->create();
        $vehicle = Vehicle::factory()->for($team)->create([
            'starting_odometer_km' => null,
        ]);
        $trip = Trip::factory()->forVehicle($vehicle)->create([
            'distance_km' => 12,
        ]);

        $estimator = new TripOdometerEstimator;
        $estimates = $estimator->estimate($vehicle, collect([$trip]));

        $this->assertNull($estimates[$trip->id]['opening_km']);
        $this->assertNull($estimates[$trip->id]['closing_km']);
    }

    public function test_estimate_from_opening_baseline_applies_prior_distance(): void
    {
        $team = Team::factory()->create();
        $vehicle = Vehicle::factory()->for($team)->create([
            'starting_odometer_km' => 1000,
        ]);
        $trip = Trip::factory()->forVehicle($vehicle)->create([
            'trip_date' => '2026-03-01',
            'distance_km' => 25,
        ]);

        $estimator = new TripOdometerEstimator;
        $estimates = $estimator->estimateFromOpeningBaseline($vehicle, collect([$trip]), 100.0);

        $this->assertSame(1100.0, $estimates[$trip->id]['opening_km']);
        $this->assertSame(1125.0, $estimates[$trip->id]['closing_km']);
    }
}
