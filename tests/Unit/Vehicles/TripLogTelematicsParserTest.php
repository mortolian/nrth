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

    public function test_maps_toyota_personal_header_as_purpose_column(): void
    {
        $parser = new TripLogTelematicsParser;

        // Toyota Fleet sometimes writes the active filter label ("Personal")
        // into the Trip Type header cell instead of "Trip Type".
        $result = $parser->tryParse([
            ['Vehicle Reg', 'CBS83344', 'VIN', 'AHTJB3DD804533836'],
            [
                'Distance',
                'Start Address',
                'End Address',
                'Start Latitude and Longitude',
                'End Latitude and Longitude',
                'Start Date',
                'End Date',
                'Time Passed',
                'Personal',
            ],
            [
                '1,00',
                'Home',
                'Shop',
                '-34,18434,22,11477',
                '-34,19073,22,11537',
                '2026/08/02 7:46:38 PM',
                '2026/08/02 7:53:03 PM',
                '6,42',
                'Personal',
            ],
            [
                '12,00',
                'Shop',
                'Office',
                '-34,19073,22,11537',
                '-34,18434,22,11477',
                '2026/08/02 8:00:00 PM',
                '2026/08/02 8:20:00 PM',
                '20,0',
                'Business',
            ],
        ]);

        $this->assertTrue($result['matched']);
        $this->assertCount(2, $result['segments']);
        $this->assertSame('private', $result['segments'][0]['purpose']);
        $this->assertSame('business', $result['segments'][1]['purpose']);
    }

    public function test_infers_purpose_column_from_cell_values_when_header_missing(): void
    {
        $parser = new TripLogTelematicsParser;

        $result = $parser->tryParse([
            [
                'Distance',
                'Start Address',
                'End Address',
                'Start Date',
                'End Date',
                'Mystery',
            ],
            ['5.0', 'A', 'B', '2026-08-01 08:00:00', '2026-08-01 08:20:00', 'Personal'],
            ['6.0', 'B', 'C', '2026-08-01 09:00:00', '2026-08-01 09:20:00', 'Business'],
            ['7.0', 'C', 'D', '2026-08-01 10:00:00', '2026-08-01 10:20:00', 'Personal'],
        ]);

        $this->assertTrue($result['matched']);
        $this->assertSame('private', $result['segments'][0]['purpose']);
        $this->assertSame('business', $result['segments'][1]['purpose']);
        $this->assertSame('private', $result['segments'][2]['purpose']);
    }
}
