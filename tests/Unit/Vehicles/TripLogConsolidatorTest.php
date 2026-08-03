<?php

namespace Tests\Unit\Vehicles;

use App\Domain\Vehicles\Services\TripLogConsolidator;
use PHPUnit\Framework\TestCase;

class TripLogConsolidatorTest extends TestCase
{
    public function test_merges_brief_stops_into_one_trip(): void
    {
        $consolidator = new TripLogConsolidator;

        $merged = $consolidator->consolidate([
            [
                'trip_date' => '2026-08-01',
                'started_at' => '2026-08-01 08:00:00',
                'ended_at' => '2026-08-01 08:20:00',
                'distance_km' => 5.0,
                'purpose' => 'business',
                'from_location' => 'Home',
                'to_location' => 'Fuel stop',
            ],
            [
                'trip_date' => '2026-08-01',
                'started_at' => '2026-08-01 08:30:00',
                'ended_at' => '2026-08-01 09:00:00',
                'distance_km' => 12.5,
                'purpose' => 'business',
                'from_location' => 'Fuel stop',
                'to_location' => 'Office',
            ],
            [
                'trip_date' => '2026-08-01',
                'started_at' => '2026-08-01 14:00:00',
                'ended_at' => '2026-08-01 14:40:00',
                'distance_km' => 18.0,
                'purpose' => 'private',
                'from_location' => 'Office',
                'to_location' => 'Home',
            ],
        ]);

        $this->assertCount(2, $merged);
        $this->assertSame('Home', $merged[0]['from_location']);
        $this->assertSame('Office', $merged[0]['to_location']);
        $this->assertSame(17.5, $merged[0]['distance_km']);
        $this->assertSame(2, $merged[0]['segments_merged']);
        $this->assertSame('private', $merged[1]['purpose']);
        $this->assertSame(1, $merged[1]['segments_merged']);
    }

    public function test_does_not_merge_across_long_gaps(): void
    {
        $consolidator = new TripLogConsolidator;

        $merged = $consolidator->consolidate([
            [
                'trip_date' => '2026-08-01',
                'started_at' => '2026-08-01 08:00:00',
                'ended_at' => '2026-08-01 08:20:00',
                'distance_km' => 5.0,
                'purpose' => 'business',
                'from_location' => 'A',
                'to_location' => 'B',
            ],
            [
                'trip_date' => '2026-08-01',
                'started_at' => '2026-08-01 12:00:00',
                'ended_at' => '2026-08-01 12:30:00',
                'distance_km' => 6.0,
                'purpose' => 'business',
                'from_location' => 'B',
                'to_location' => 'C',
            ],
        ]);

        $this->assertCount(2, $merged);
    }
}
