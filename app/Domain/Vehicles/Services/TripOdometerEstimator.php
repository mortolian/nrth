<?php

namespace App\Domain\Vehicles\Services;

use App\Domain\Vehicles\Models\Trip;
use App\Domain\Vehicles\Models\Vehicle;
use Illuminate\Support\Collection;

class TripOdometerEstimator
{
    /**
     * Estimate opening/closing odometer for trips on a vehicle.
     *
     * Treats {@see Vehicle::$current_odometer_km} as the reading after all known trips,
     * then walks chronologically forward from (current − total distance).
     *
     * @param  Collection<int, Trip>  $allVehicleTripsChronological  All trips for the vehicle, oldest first
     * @return array<int, array{opening_km: float|null, closing_km: float|null}>
     */
    public function estimate(Vehicle $vehicle, Collection $allVehicleTripsChronological): array
    {
        $estimates = [];

        foreach ($allVehicleTripsChronological as $trip) {
            $estimates[(int) $trip->id] = [
                'opening_km' => null,
                'closing_km' => null,
            ];
        }

        if ($vehicle->current_odometer_km === null || $allVehicleTripsChronological->isEmpty()) {
            return $estimates;
        }

        $totalDistance = round((float) $allVehicleTripsChronological->sum(
            fn (Trip $trip): float => (float) $trip->distance_km
        ), 1);

        $running = round((float) $vehicle->current_odometer_km - $totalDistance, 1);

        foreach ($allVehicleTripsChronological as $trip) {
            $distance = round((float) $trip->distance_km, 1);
            $opening = $running;
            $closing = round($running + $distance, 1);

            $estimates[(int) $trip->id] = [
                'opening_km' => $opening,
                'closing_km' => $closing,
            ];

            $running = $closing;
        }

        return $estimates;
    }

    /**
     * @param  Collection<int, Trip>  $trips
     * @return Collection<int, Trip>
     */
    public function chronological(Collection $trips): Collection
    {
        return $trips
            ->sortBy(function (Trip $trip): array {
                $timestamp = optional($trip->started_at)->getTimestamp()
                    ?? optional($trip->trip_date)?->startOfDay()->getTimestamp()
                    ?? 0;

                return [$timestamp, (int) $trip->id];
            })
            ->values();
    }
}
