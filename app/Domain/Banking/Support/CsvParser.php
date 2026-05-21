<?php

namespace App\Domain\Banking\Support;

final class CsvParser
{
    /**
     * @return array{delimiter: string, headers: list<string>, rows: list<list<string>>}
     */
    public function sniffAndRead(string $path, int $previewLimit = 10): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open CSV file.');
        }

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);

            return ['delimiter' => ',', 'headers' => [], 'rows' => []];
        }

        $delimiter = $this->detectDelimiter($firstLine);
        rewind($handle);

        $headers = [];
        $rows = [];
        $lineNumber = 0;

        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($lineNumber === 0) {
                $headers = array_map(fn ($h) => trim((string) $h), $data);
                $lineNumber++;

                continue;
            }

            if ($this->isEmptyRow($data)) {
                continue;
            }

            $rows[] = array_map(fn ($v) => trim((string) $v), $data);

            if (count($rows) >= $previewLimit) {
                break;
            }

            $lineNumber++;
        }

        fclose($handle);

        return [
            'delimiter' => $delimiter,
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    /**
     * @return list<list<string>>
     */
    public function readAllRows(string $path, string $delimiter): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open CSV file.');
        }

        $rows = [];
        $isHeader = true;

        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($isHeader) {
                $isHeader = false;

                continue;
            }

            if ($this->isEmptyRow($data)) {
                continue;
            }

            $rows[] = array_map(fn ($v) => trim((string) $v), $data);
        }

        fclose($handle);

        return $rows;
    }

    private function detectDelimiter(string $line): string
    {
        $semicolons = substr_count($line, ';');
        $commas = substr_count($line, ',');

        return $semicolons > $commas ? ';' : ',';
    }

    /**
     * @param  list<string|null>  $row
     */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
