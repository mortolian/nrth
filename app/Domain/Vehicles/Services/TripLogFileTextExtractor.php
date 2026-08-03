<?php

namespace App\Domain\Vehicles\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use Throwable;

/**
 * Turns trip-log uploads (CSV / XLSX / TXT) into a row matrix for parsing.
 */
final class TripLogFileTextExtractor
{
    /** Cap after skipping empty padded rows (fleet XLSX files often have huge empty ranges). */
    public const MAX_DATA_ROWS = 5000;

    public function __construct(
        private readonly TripLogXlsxStreamer $xlsxStreamer,
    ) {}

    /**
     * @return array{
     *     rows: list<list<string>>,
     *     truncated: bool,
     *     text: string,
     *     extension: string
     * }
     */
    public function extract(UploadedFile $file): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $path = $file->getRealPath();
        if ($path === false || $path === '') {
            throw ValidationException::withMessages([
                'file' => __('Could not read the uploaded file.'),
            ]);
        }

        $rows = match (true) {
            in_array($extension, ['xlsx', 'xls', 'ods'], true) => $this->fromSpreadsheet($path),
            in_array($extension, ['csv', 'txt', 'tsv'], true) => $this->fromDelimited($path, $extension === 'tsv' ? "\t" : null),
            default => throw ValidationException::withMessages([
                'file' => __('Upload a CSV, TXT, or Excel (.xlsx) trip export.'),
            ]),
        };

        $hitCap = count($rows) > self::MAX_DATA_ROWS;
        if ($hitCap) {
            $rows = array_slice($rows, 0, self::MAX_DATA_ROWS);
        }

        return [
            'rows' => $rows,
            'truncated' => $hitCap,
            'text' => $this->rowsToTsv($rows),
            'extension' => $extension,
        ];
    }

    public function isTabular(UploadedFile $file): bool
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $mime = (string) ($file->getMimeType() ?: '');

        if (in_array($extension, ['csv', 'txt', 'tsv', 'xlsx', 'xls', 'ods'], true)) {
            return true;
        }

        return in_array($mime, [
            'text/csv',
            'text/plain',
            'text/tab-separated-values',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.oasis.opendocument.spreadsheet',
        ], true);
    }

    public function isVisionDocument(UploadedFile $file): bool
    {
        $mime = (string) ($file->getMimeType() ?: '');
        $extension = strtolower((string) $file->getClientOriginalExtension());

        return $mime === 'application/pdf'
            || $extension === 'pdf'
            || str_starts_with($mime, 'image/');
    }

    /**
     * @return list<list<string>>
     */
    private function fromSpreadsheet(string $path): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // Prefer streaming XLSX reads — fleet exports often pad tens of thousands of empty rows.
        if ($extension === 'xlsx') {
            try {
                $matrix = $this->xlsxStreamer->readNonEmptyRows($path, self::MAX_DATA_ROWS + 1);
            } catch (ValidationException $e) {
                throw $e;
            } catch (Throwable) {
                $matrix = $this->fromSpreadsheetViaPhpSpreadsheet($path);
            }
        } else {
            $matrix = $this->fromSpreadsheetViaPhpSpreadsheet($path);
        }

        if ($matrix === []) {
            throw ValidationException::withMessages([
                'file' => __('This spreadsheet has no trip rows.'),
            ]);
        }

        return $matrix;
    }

    /**
     * @return list<list<string>>
     */
    private function fromSpreadsheetViaPhpSpreadsheet(string $path): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            if (method_exists($reader, 'setReadEmptyCells')) {
                $reader->setReadEmptyCells(false);
            }

            // Hard cap sheet rows so padded dimensions cannot exhaust memory.
            $maxSheetRow = self::MAX_DATA_ROWS + 100;
            $reader->setReadFilter(new class($maxSheetRow) implements IReadFilter
            {
                public function __construct(private readonly int $maxRow) {}

                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row <= $this->maxRow;
                }
            });

            $spreadsheet = $reader->load($path);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'file' => __('Could not read this spreadsheet. Export again as CSV or XLSX.'),
            ]);
        }

        $sheet = $spreadsheet->getActiveSheet();
        $matrix = [];
        foreach ($sheet->toArray(null, false, false, false) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $cells = array_map(fn ($cell): string => $this->cellToString($cell), $row);
            if ($this->rowIsEmpty($cells)) {
                continue;
            }
            $matrix[] = $cells;
            if (count($matrix) >= self::MAX_DATA_ROWS + 50) {
                break;
            }
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $matrix;
    }

    /**
     * @return list<list<string>>
     */
    private function fromDelimited(string $path, ?string $delimiter): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages([
                'file' => __('Could not read the uploaded file.'),
            ]);
        }

        $matrix = [];
        try {
            $firstLine = fgets($handle);
            if ($firstLine === false) {
                throw ValidationException::withMessages([
                    'file' => __('This file is empty.'),
                ]);
            }

            $delimiter ??= $this->detectDelimiter($firstLine);
            rewind($handle);

            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                $cells = array_map(fn ($cell): string => $this->cellToString($cell), $row);
                if ($this->rowIsEmpty($cells)) {
                    continue;
                }
                $matrix[] = $cells;
            }
        } finally {
            fclose($handle);
        }

        if ($matrix === []) {
            throw ValidationException::withMessages([
                'file' => __('This file has no trip rows.'),
            ]);
        }

        return $matrix;
    }

    private function detectDelimiter(string $line): string
    {
        $candidates = [',' => 0, ';' => 0, "\t" => 0, '|' => 0];
        foreach (array_keys($candidates) as $delimiter) {
            $candidates[$delimiter] = substr_count($line, $delimiter);
        }
        arsort($candidates);
        $best = array_key_first($candidates);

        return is_string($best) && ($candidates[$best] ?? 0) > 0 ? $best : ',';
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function rowsToTsv(array $rows): string
    {
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = implode("\t", array_map(
                fn (string $cell): string => str_replace(["\t", "\r", "\n"], ' ', $cell),
                $row
            ));
        }

        return implode("\n", $lines);
    }

    private function cellToString(mixed $cell): string
    {
        if ($cell === null) {
            return '';
        }
        if (is_bool($cell)) {
            return $cell ? '1' : '0';
        }
        if (is_float($cell) || is_int($cell)) {
            return (string) $cell;
        }

        return trim((string) $cell);
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
