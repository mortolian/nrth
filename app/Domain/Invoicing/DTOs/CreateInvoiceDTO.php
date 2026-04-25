<?php

namespace App\Domain\Invoicing\DTOs;

readonly class CreateInvoiceDTO
{
    /**
     * @param  array<int, array{description: string, quantity: float|int|string, unit_price_cents: int, vat_rate?: float|int|string}>  $lineItems
     */
    public function __construct(
        public int $teamId,
        public int $clientId,
        public string $issueDate,
        public ?string $dueDate = null,
        public string $currency = 'ZAR',
        public ?string $reference = null,
        public ?string $notes = null,
        public ?string $footer = null,
        public array $lineItems = [],
    ) {}
}
