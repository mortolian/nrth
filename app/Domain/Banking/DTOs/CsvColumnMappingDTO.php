<?php

namespace App\Domain\Banking\DTOs;

readonly class CsvColumnMappingDTO
{
    public function __construct(
        public string $transactionDate,
        public string $description,
        public ?string $amount = null,
        public ?string $debit = null,
        public ?string $credit = null,
        public ?string $reference = null,
        public ?string $runningBalance = null,
        public ?string $valueDate = null,
        public ?string $dateFormat = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'transaction_date' => $this->transactionDate,
            'description' => $this->description,
            'amount' => $this->amount,
            'debit' => $this->debit,
            'credit' => $this->credit,
            'reference' => $this->reference,
            'running_balance' => $this->runningBalance,
            'value_date' => $this->valueDate,
            'date_format' => $this->dateFormat,
        ], fn ($value) => $value !== null && $value !== '');
    }
}
