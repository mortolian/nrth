<?php

namespace App\Domain\Takeout\DTOs;

final class TakeoutDocumentExportResult
{
    /**
     * @param  array<int, string>  $invoicePdfFilenames
     * @param  array<int, string>  $expenseReceiptFilenames
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $invoicePdfFilenames = [],
        public array $expenseReceiptFilenames = [],
        public array $warnings = [],
    ) {}
}
