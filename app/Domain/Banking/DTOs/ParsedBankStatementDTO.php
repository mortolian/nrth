<?php

namespace App\Domain\Banking\DTOs;

readonly class ParsedBankStatementDTO
{
    /**
     * @param  list<ParsedTransactionDTO>  $transactions
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public array $transactions,
        public array $metadata = [],
    ) {}
}
