<?php

namespace App\Domain\SampleData\Chapters;

use App\Domain\SampleData\SampleChapter;
use App\Domain\SampleData\SampleContext;
use App\Domain\Vehicles\Enums\TripPurpose;
use App\Domain\Vehicles\Models\Trip;
use App\Domain\Vehicles\Models\Vehicle;

final class TravelChapter implements SampleChapter
{
    public function key(): string
    {
        return 'travel';
    }

    public function seed(SampleContext $context): void
    {
        $vehicle = Vehicle::query()->create([
            'team_id' => $context->team->id,
            'name' => 'Studio car',
            'make' => 'Toyota',
            'model' => 'Corolla',
            'year' => 2022,
            'registration_number' => 'CA 482-119',
            'license_disk_expires_on' => $context->calendar->asOf->copy()->addMonths(4)->toDateString(),
            'starting_odometer_km' => 41200,
            'is_active' => true,
        ]);

        $odometer = 41200.0;
        foreach ($context->calendar->postedMonths() as $index => $month) {
            $date = $month->copy()->day(min(8, $month->daysInMonth));
            if ($date->greaterThan($context->calendar->asOf)) {
                $date = $context->calendar->asOf->copy();
            }

            $odometer = $this->trip($context, $vehicle, $date->toDateString(), $odometer, 48.6, TripPurpose::Business, 'Studio', 'Client site, Stellenbosch');

            if ($index % 2 === 1) {
                $odometer = $this->trip($context, $vehicle, $date->copy()->addDays(3)->min($context->calendar->asOf)->toDateString(), $odometer, 12.4, TripPurpose::Private, 'Home', 'Claremont');
            }
        }
    }

    private function trip(
        SampleContext $context,
        Vehicle $vehicle,
        string $date,
        float $startKm,
        float $distance,
        TripPurpose $purpose,
        string $from,
        string $to,
    ): float {
        $end = round($startKm + $distance, 1);
        Trip::query()->create([
            'team_id' => $context->team->id,
            'vehicle_id' => $vehicle->id,
            'trip_date' => $date,
            'distance_km' => $distance,
            'purpose' => $purpose,
            'start_odometer_km' => $startKm,
            'end_odometer_km' => $end,
            'from_location' => $from,
            'to_location' => $to,
        ]);

        return $end;
    }
}
