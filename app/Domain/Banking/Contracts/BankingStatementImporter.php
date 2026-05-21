<?php

namespace App\Domain\Banking\Contracts;

use App\Domain\Banking\DTOs\ParsedBankStatementDTO;

interface BankingStatementImporter
{
    public function supports(string $mimeType, string $extension): bool;

    /**
     * @param  array<string, mixed>  $options
     */
    public function parse(string $path, array $options = []): ParsedBankStatementDTO;
}
