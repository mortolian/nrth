<?php

namespace App\Domain\Takeout\Support;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class TakeoutSpreadsheetWriter
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<scalar|null>>  $rows
     */
    public function writePair(string $directory, string $basename, array $headers, array $rows): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $this->writeCsv($directory.'/'.$basename.'.csv', $headers, $rows);
        $this->writeXlsx($directory.'/'.$basename.'.xlsx', $headers, $rows);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<scalar|null>>  $rows
     */
    private function writeCsv(string $path, array $headers, array $rows): void
    {
        $handle = fopen($path, 'w');
        if ($handle === false) {
            throw new \RuntimeException('Could not write CSV: '.$path);
        }

        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<scalar|null>>  $rows
     */
    private function writeXlsx(string $path, array $headers, array $rows): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $allRows = array_merge([$headers], $rows);
        $rowIndex = 1;
        foreach ($allRows as $row) {
            $colIndex = 1;
            foreach ($row as $cell) {
                $sheet->setCellValue([$colIndex, $rowIndex], $cell);
                $colIndex++;
            }
            $rowIndex++;
        }

        (new Xlsx($spreadsheet))->save($path);
    }
}
