<?php

namespace App\Domain\Banking\DTOs;

use App\Domain\Banking\Enums\TransactionDirection;

readonly class ParsedTransactionDTO
{
    public function __construct(
        public string $transactionDate,
        public string $description,
        public string $amount,
        public TransactionDirection $direction,
        public ?string $valueDate = null,
        public ?string $reference = null,
        public ?string $runningBalance = null,
        public ?string $currency = null,
        public ?array $metadata = null,
    ) {}
}
