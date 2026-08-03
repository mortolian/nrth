<?php

namespace Tests\Unit\Vehicles;

use App\Domain\Vehicles\Services\TripLogFileTextExtractor;
use App\Domain\Vehicles\Services\TripLogTelematicsParser;
use App\Domain\Vehicles\Services\TripLogXlsxStreamer;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\TestCase;

class TripLogXlsxStreamerTest extends TestCase
{
    public function test_streams_toyota_logbook_without_oom(): void
    {
        $path = '/Users/gideon/Desktop/LogBook_10539681_639213544054993049_2026-8-3-45.xlsx';
        if (! is_file($path)) {
            $this->markTestSkipped('Sample Toyota log book not present on this machine.');
        }

        $started = microtime(true);
        $extractor = new TripLogFileTextExtractor(new TripLogXlsxStreamer);
        $file = new UploadedFile($path, basename($path), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $extracted = $extractor->extract($file);
        $elapsed = microtime(true) - $started;

        $this->assertLessThan(5.0, $elapsed, 'XLSX extract should finish quickly');
        $this->assertGreaterThan(100, count($extracted['rows']));
        $this->assertFalse($extracted['truncated']);

        $parser = new TripLogTelematicsParser;
        $result = $parser->tryParse($extracted['rows']);
        $this->assertTrue($result['matched']);
        $this->assertSame('CBS83344', $result['vehicle_registration']);
        $this->assertGreaterThan(100, $result['source_segments_count']);
        $this->assertSame(1.0, $result['segments'][0]['distance_km']);
    }
}
