<?php

namespace App\Domain\Vehicles\Services;

use Illuminate\Validation\ValidationException;
use Throwable;
use XMLReader;
use ZipArchive;

/**
 * Streams XLSX sheet rows without materializing empty styled cells (common in fleet exports).
 */
final class TripLogXlsxStreamer
{
    /**
     * @return list<list<string>>
     */
    public function readNonEmptyRows(string $path, int $maxRows = 0): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages([
                'file' => __('Could not read this spreadsheet. Export again as CSV or XLSX.'),
            ]);
        }

        try {
            $shared = $this->loadSharedStrings($zip);
            $sheetPath = $this->firstWorksheetPath($zip);
            $xml = $zip->getFromName($sheetPath);
            if ($xml === false) {
                throw ValidationException::withMessages([
                    'file' => __('Could not read this spreadsheet. Export again as CSV or XLSX.'),
                ]);
            }
        } finally {
            $zip->close();
        }

        return $this->parseSheetXml($xml, $shared, $maxRows);
    }

    /**
     * @return list<string>
     */
    private function loadSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $shared = [];
        $reader = new XMLReader;
        if (! @$reader->XML($xml)) {
            return [];
        }

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'si') {
                continue;
            }

            $siXml = $reader->readOuterXML();
            if ($siXml === '') {
                $shared[] = '';

                continue;
            }

            $text = '';
            try {
                $si = new XMLReader;
                $si->XML($siXml);
                while ($si->read()) {
                    if ($si->nodeType === XMLReader::ELEMENT && $si->localName === 't') {
                        $text .= (string) $si->readString();
                    }
                }
                $si->close();
            } catch (Throwable) {
                $text = '';
            }

            $shared[] = $text;
        }

        $reader->close();

        return $shared;
    }

    private function firstWorksheetPath(ZipArchive $zip): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook === false || $rels === false) {
            return 'xl/worksheets/sheet1.xml';
        }

        $ridToTarget = [];
        $relsReader = new XMLReader;
        if (@$relsReader->XML($rels)) {
            while ($relsReader->read()) {
                if ($relsReader->nodeType === XMLReader::ELEMENT && $relsReader->localName === 'Relationship') {
                    $id = (string) $relsReader->getAttribute('Id');
                    $target = (string) $relsReader->getAttribute('Target');
                    if ($id !== '' && $target !== '') {
                        $ridToTarget[$id] = $target;
                    }
                }
            }
            $relsReader->close();
        }

        $workbookReader = new XMLReader;
        if (@$workbookReader->XML($workbook)) {
            while ($workbookReader->read()) {
                if ($workbookReader->nodeType === XMLReader::ELEMENT && $workbookReader->localName === 'sheet') {
                    $rid = (string) $workbookReader->getAttribute('r:id');
                    if ($rid === '') {
                        // Some writers use namespaced r:id differently.
                        $rid = (string) $workbookReader->getAttributeNs('id', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                    }
                    $target = $ridToTarget[$rid] ?? null;
                    if (is_string($target) && $target !== '') {
                        $workbookReader->close();
                        $normalized = ltrim(str_replace('\\', '/', $target), '/');
                        if (! str_starts_with($normalized, 'xl/')) {
                            $normalized = 'xl/'.$normalized;
                        }

                        return $normalized;
                    }
                }
            }
            $workbookReader->close();
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /**
     * @param  list<string>  $shared
     * @return list<list<string>>
     */
    private function parseSheetXml(string $xml, array $shared, int $maxRows): array
    {
        $reader = new XMLReader;
        if (! @$reader->XML($xml)) {
            throw ValidationException::withMessages([
                'file' => __('Could not read this spreadsheet. Export again as CSV or XLSX.'),
            ]);
        }

        $matrix = [];
        $emptyStreak = 0;

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                continue;
            }

            $rowXml = $reader->readOuterXML();
            if ($rowXml === '') {
                continue;
            }

            // Fleet exports often pad tens of thousands of empty styled cells after the data.
            // Toyota exports may use inlineStr (<is><t>…) with no sharedStrings / <v> cells.
            $hasValue = str_contains($rowXml, '<v>')
                || str_contains($rowXml, '<v ')
                || str_contains($rowXml, '<is>')
                || str_contains($rowXml, 'inlineStr');
            if (! $hasValue) {
                $emptyStreak++;
                if ($emptyStreak >= 40 && $matrix !== []) {
                    break;
                }

                continue;
            }

            $cells = $this->parseRowCells($rowXml, $shared);
            if ($this->rowIsEmpty($cells)) {
                $emptyStreak++;
                if ($emptyStreak >= 40 && $matrix !== []) {
                    break;
                }

                continue;
            }

            $emptyStreak = 0;
            $matrix[] = $cells;
            if ($maxRows > 0 && count($matrix) >= $maxRows) {
                break;
            }
        }

        $reader->close();

        return $matrix;
    }

    /**
     * @param  list<string>  $shared
     * @return list<string>
     */
    private function parseRowCells(string $rowXml, array $shared): array
    {
        $byIndex = [];
        $maxIndex = -1;

        $reader = new XMLReader;
        $reader->XML($rowXml);
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'c') {
                continue;
            }

            $ref = (string) $reader->getAttribute('r');
            $type = (string) $reader->getAttribute('t');
            $index = $this->columnIndexFromRef($ref);
            if ($index === null) {
                continue;
            }

            $value = '';
            $cellXml = $reader->readOuterXML();
            $cellReader = new XMLReader;
            $cellReader->XML($cellXml);
            if ($type === 'inlineStr') {
                while ($cellReader->read()) {
                    if ($cellReader->nodeType === XMLReader::ELEMENT && $cellReader->localName === 't') {
                        $value .= (string) $cellReader->readString();
                    }
                }
            } else {
                while ($cellReader->read()) {
                    if ($cellReader->nodeType === XMLReader::ELEMENT && $cellReader->localName === 'v') {
                        $value = (string) $cellReader->readString();
                        break;
                    }
                }
            }
            $cellReader->close();

            if ($type === 's' && $value !== '' && ctype_digit($value)) {
                $value = $shared[(int) $value] ?? '';
            }

            $byIndex[$index] = trim($value);
            $maxIndex = max($maxIndex, $index);
        }
        $reader->close();

        if ($maxIndex < 0) {
            return [];
        }

        $row = [];
        for ($i = 0; $i <= $maxIndex; $i++) {
            $row[] = $byIndex[$i] ?? '';
        }

        return $row;
    }

    private function columnIndexFromRef(string $ref): ?int
    {
        if (! preg_match('/^([A-Z]+)/i', $ref, $m)) {
            return null;
        }

        $letters = strtoupper($m[1]);
        $index = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $index = ($index * 26) + (ord($letters[$i]) - 64);
        }

        return $index - 1;
    }

    /**
     * @param  list<string>  $cells
     */
    private function rowIsEmpty(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
