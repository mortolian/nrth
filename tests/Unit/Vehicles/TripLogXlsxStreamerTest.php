<?php

namespace Tests\Unit\Vehicles;

use App\Domain\Vehicles\Services\TripLogFileTextExtractor;
use App\Domain\Vehicles\Services\TripLogTelematicsParser;
use App\Domain\Vehicles\Services\TripLogXlsxStreamer;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class TripLogXlsxStreamerTest extends TestCase
{
    public function test_streams_toyota_logbook_without_oom(): void
    {
        $candidates = [
            '/Users/gideon/Desktop/NRTH/LogBook_updated_march_2026 - July 2026.xlsx',
            '/Users/gideon/Desktop/NRTH/LogBook_10539681_639213544054993049_2026-8-3-45.xlsx',
            '/Users/gideon/Desktop/LogBook_10539681_639213544054993049_2026-8-3-45.xlsx',
        ];
        $path = null;
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $path = $candidate;
                break;
            }
        }
        if ($path === null) {
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

        $purposes = array_count_values(array_column($result['segments'], 'purpose'));
        $this->assertArrayHasKey('private', $purposes);
        $this->assertArrayHasKey('business', $purposes);
        $this->assertGreaterThan(10, $purposes['private']);
        $this->assertGreaterThan(10, $purposes['business']);
    }

    public function test_reads_inline_string_cells_without_shared_strings(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'trip-inline-').'.xlsx';
        $this->writeMinimalInlineStrXlsx($path);

        try {
            $rows = (new TripLogXlsxStreamer)->readNonEmptyRows($path);
            $this->assertCount(3, $rows);
            $this->assertSame('Distance', $rows[0][0]);
            $this->assertSame('Personal', $rows[0][4]);
            $this->assertSame('Personal', $rows[1][4]);
            $this->assertSame('Business', $rows[2][4]);

            $parser = new TripLogTelematicsParser;
            $result = $parser->tryParse($rows);
            $this->assertTrue($result['matched']);
            $this->assertSame('private', $result['segments'][0]['purpose']);
            $this->assertSame('business', $result['segments'][1]['purpose']);
        } finally {
            @unlink($path);
        }
    }

    private function writeMinimalInlineStrXlsx(string $path): void
    {
        $sheet = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetData>
    <row r="1">
      <c r="A1" t="inlineStr"><is><t>Distance</t></is></c>
      <c r="B1" t="inlineStr"><is><t>Start Address</t></is></c>
      <c r="C1" t="inlineStr"><is><t>End Address</t></is></c>
      <c r="D1" t="inlineStr"><is><t>Start Date</t></is></c>
      <c r="E1" t="inlineStr"><is><t>Personal</t></is></c>
    </row>
    <row r="2">
      <c r="A2" t="inlineStr"><is><t>5,00</t></is></c>
      <c r="B2" t="inlineStr"><is><t>Home</t></is></c>
      <c r="C2" t="inlineStr"><is><t>Shop</t></is></c>
      <c r="D2" t="inlineStr"><is><t>2026/08/02 7:00:00 PM</t></is></c>
      <c r="E2" t="inlineStr"><is><t>Personal</t></is></c>
    </row>
    <row r="3">
      <c r="A3" t="inlineStr"><is><t>8,00</t></is></c>
      <c r="B3" t="inlineStr"><is><t>Shop</t></is></c>
      <c r="C3" t="inlineStr"><is><t>Office</t></is></c>
      <c r="D3" t="inlineStr"><is><t>2026/08/02 8:00:00 PM</t></is></c>
      <c r="E3" t="inlineStr"><is><t>Business</t></is></c>
    </row>
  </sheetData>
</worksheet>
XML;

        $workbook = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Sheet1" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>
XML;

        $rels = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>
XML;

        $contentTypes = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>
XML;

        $rootRels = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML;

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rootRels);
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
    }
}
