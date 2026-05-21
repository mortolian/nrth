<?php

namespace App\Domain\Banking\Importers;

use App\Domain\Banking\Contracts\BankingStatementImporter;
use App\Domain\Banking\DTOs\CsvColumnMappingDTO;
use App\Domain\Banking\DTOs\ParsedBankStatementDTO;
use App\Domain\Banking\DTOs\ParsedTransactionDTO;
use App\Domain\Banking\Enums\TransactionDirection;
use App\Domain\Banking\Support\AmountParser;
use App\Domain\Banking\Support\CsvParser;
use App\Domain\Banking\Support\DateParser;
use Illuminate\Validation\ValidationException;

final class CsvBankStatementImporter implements BankingStatementImporter
{
    public function __construct(
        private readonly CsvParser $csvParser,
        private readonly AmountParser $amountParser,
        private readonly DateParser $dateParser,
    ) {}

    public function supports(string $mimeType, string $extension): bool
    {
        $extension = strtolower(ltrim($extension, '.'));

        return in_array($extension, ['csv', 'txt'], true);
    }

    public function parse(string $path, array $options = []): ParsedBankStatementDTO
    {
        $mapping = $this->resolveMapping($options);
        $delimiter = (string) ($options['delimiter'] ?? $this->csvParser->sniffAndRead($path)['delimiter']);
        $headers = $options['headers'] ?? $this->csvParser->sniffAndRead($path)['headers'];
        $rows = $this->csvParser->readAllRows($path, $delimiter);

        $headerIndex = $this->indexHeaders($headers);
        $transactions = [];
        $errors = [];

        foreach ($rows as $rowIndex => $row) {
            try {
                $transaction = $this->mapRow($row, $headerIndex, $mapping, $rowIndex);
                if ($transaction !== null) {
                    $transactions[] = $transaction;
                }
            } catch (\Throwable $e) {
                $errors[] = ['row' => $rowIndex + 2, 'message' => $e->getMessage()];
            }
        }

        return new ParsedBankStatementDTO(
            transactions: $transactions,
            metadata: [
                'mapping' => $mapping->toArray(),
                'delimiter' => $delimiter,
                'headers' => $headers,
                'parse_errors' => $errors,
            ],
        );
    }

    /**
     * @return array{delimiter: string, headers: list<string>, rows: list<list<string>>}
     */
    public function preview(string $path, int $limit = 10): array
    {
        return $this->csvParser->sniffAndRead($path, $limit);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function resolveMapping(array $options): CsvColumnMappingDTO
    {
        $mapping = $options['mapping'] ?? null;
        if (! is_array($mapping)) {
            throw ValidationException::withMessages([
                'mapping' => __('CSV column mapping is required.'),
            ]);
        }

        return new CsvColumnMappingDTO(
            transactionDate: (string) ($mapping['transaction_date'] ?? ''),
            description: (string) ($mapping['description'] ?? ''),
            amount: isset($mapping['amount']) ? (string) $mapping['amount'] : null,
            debit: isset($mapping['debit']) ? (string) $mapping['debit'] : null,
            credit: isset($mapping['credit']) ? (string) $mapping['credit'] : null,
            reference: isset($mapping['reference']) ? (string) $mapping['reference'] : null,
            runningBalance: isset($mapping['running_balance']) ? (string) $mapping['running_balance'] : null,
            valueDate: isset($mapping['value_date']) ? (string) $mapping['value_date'] : null,
            dateFormat: isset($mapping['date_format']) ? (string) $mapping['date_format'] : null,
        );
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, int>
     */
    private function indexHeaders(array $headers): array
    {
        $index = [];
        foreach ($headers as $i => $header) {
            $index[$header] = $i;
        }

        return $index;
    }

    /**
     * @param  list<string>  $row
     * @param  array<string, int>  $headerIndex
     */
    private function mapRow(array $row, array $headerIndex, CsvColumnMappingDTO $mapping, int $rowIndex): ?ParsedTransactionDTO
    {
        $date = $this->cell($row, $headerIndex, $mapping->transactionDate);
        $description = $this->cell($row, $headerIndex, $mapping->description);
        $transactionDate = $this->dateParser->parse($date, $mapping->dateFormat);

        if ($transactionDate === null || trim($description) === '') {
            throw new \InvalidArgumentException('Missing required date or description.');
        }

        [$amount, $direction] = $this->resolveAmountAndDirection($row, $headerIndex, $mapping);

        if ($amount === null || bccomp($amount, '0', 2) === 0) {
            return null;
        }

        $absoluteAmount = ltrim($amount, '-');
        if (bccomp($absoluteAmount, '0', 2) === 0) {
            return null;
        }

        $valueDate = null;
        if ($mapping->valueDate !== null && $mapping->valueDate !== '') {
            $valueDate = $this->dateParser->parse(
                $this->cell($row, $headerIndex, $mapping->valueDate),
                $mapping->dateFormat
            );
        }

        $runningBalance = null;
        if ($mapping->runningBalance !== null && $mapping->runningBalance !== '') {
            $runningBalance = $this->amountParser->parse(
                $this->cell($row, $headerIndex, $mapping->runningBalance)
            );
        }

        $reference = null;
        if ($mapping->reference !== null && $mapping->reference !== '') {
            $reference = $this->cell($row, $headerIndex, $mapping->reference) ?: null;
        }

        return new ParsedTransactionDTO(
            transactionDate: $transactionDate,
            description: $description,
            amount: $absoluteAmount,
            direction: $direction,
            valueDate: $valueDate,
            reference: $reference,
            runningBalance: $runningBalance,
            metadata: ['row' => $rowIndex + 2],
        );
    }

    /**
     * @param  list<string>  $row
     * @param  array<string, int>  $headerIndex
     * @return array{0: string, 1: TransactionDirection}
     */
    private function resolveAmountAndDirection(array $row, array $headerIndex, CsvColumnMappingDTO $mapping): array
    {
        if ($mapping->amount !== null && $mapping->amount !== '') {
            $parsed = $this->amountParser->parse($this->cell($row, $headerIndex, $mapping->amount));
            if ($parsed === null) {
                throw new \InvalidArgumentException('Invalid amount.');
            }

            $direction = bccomp($parsed, '0', 2) < 0
                ? TransactionDirection::Debit
                : TransactionDirection::Credit;

            return [ltrim($parsed, '-'), $direction];
        }

        $debit = $mapping->debit !== null && $mapping->debit !== ''
            ? $this->amountParser->parse($this->cell($row, $headerIndex, $mapping->debit))
            : null;
        $credit = $mapping->credit !== null && $mapping->credit !== ''
            ? $this->amountParser->parse($this->cell($row, $headerIndex, $mapping->credit))
            : null;

        if ($debit !== null && bccomp($debit, '0', 2) !== 0) {
            return [ltrim($debit, '-'), TransactionDirection::Debit];
        }

        if ($credit !== null && bccomp($credit, '0', 2) !== 0) {
            return [ltrim($credit, '-'), TransactionDirection::Credit];
        }

        throw new \InvalidArgumentException('No amount found in row.');
    }

    /**
     * @param  list<string>  $row
     * @param  array<string, int>  $headerIndex
     */
    private function cell(array $row, array $headerIndex, string $column): string
    {
        if (! isset($headerIndex[$column])) {
            return '';
        }

        return $row[$headerIndex[$column]] ?? '';
    }
}
