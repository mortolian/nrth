<?php

namespace Tests\Unit\Vehicles;

use App\Domain\Vehicles\Services\TripLogTelematicsParser;
use PHPUnit\Framework\TestCase;

class TripLogTelematicsParserTest extends TestCase
{
    public function test_parses_toyota_style_columns(): void
    {
        $parser = new TripLogTelematicsParser;

        $result = $parser->tryParse([
            ['Name', 'Driver', 'Vehicle Description', 'Toyota Corolla', 'Vehicle Reg', 'CA 123 GP', 'VIN', 'JTDBR32E720123456'],
            [
                'Distance',
                'Start Address',
                'End Address',
                'Start Latitude and Longitude',
                'End Latitude and Longitude',
                'Start Date',
                'End Date',
                'Time Passed',
                'Trip Type',
            ],
            [
                '4.2',
                'Cape Town CBD',
                'Sea Point',
                '-33.9249, 18.4241',
                '-33.9250, 18.3900',
                '2026-08-01 08:00:00',
                '2026-08-01 08:25:00',
                '0:25:00',
                'Personal',
            ],
            [
                '11.0',
                'Sea Point',
                'Camps Bay',
                '-33.9250, 18.3900',
                '-33.9500, 18.3770',
                '2026-08-01 08:40:00',
                '2026-08-01 09:05:00',
                '0:25:00',
                'Business',
            ],
        ]);

        $this->assertTrue($result['matched']);
        $this->assertSame('CA 123 GP', $result['vehicle_registration']);
        $this->assertSame('JTDBR32E720123456', $result['vehicle_vin']);
        $this->assertCount(2, $result['segments']);
        $this->assertSame('private', $result['segments'][0]['purpose']);
        $this->assertSame('business', $result['segments'][1]['purpose']);
        $this->assertSame(4.2, $result['segments'][0]['distance_km']);
        $this->assertSame('Cape Town CBD', $result['segments'][0]['from_location']);
        $this->assertNotNull($result['segments'][0]['start_latitude']);
    }

    public function test_parses_european_decimals_and_latlng(): void
    {
        $parser = new TripLogTelematicsParser;

        $result = $parser->tryParse([
            ['Vehicle Reg', 'CA 123 GP', 'VIN', 'JTDBR32E720123456'],
            [
                'Distance',
                'Start Address',
                'End Address',
                'Start Latitude and Longitude',
                'End Latitude and Longitude',
                'Start Date',
                'End Date',
                'Time Passed',
                'Trip Type',
            ],
            [
                '53,00',
                'Home',
                'Office',
                '-34,19084,22,11535',
                '-34,18434,22,11477',
                '2026/08/02 7:34:46 PM',
                '2026/08/02 7:41:58 PM',
                '7,2',
                'Personal',
            ],
        ]);

        $this->assertTrue($result['matched']);
        $segment = $result['segments'][0];
        $this->assertSame(53.0, $segment['distance_km']);
        $this->assertSame('private', $segment['purpose']);
        $this->assertSame(-34.19084, $segment['start_latitude']);
        $this->assertSame(22.11535, $segment['start_longitude']);
        $this->assertSame(432, $segment['duration_seconds']); // 7.2 minutes
    }
}
