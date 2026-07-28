<?php

namespace App\Domain\Invoicing\DTOs;

readonly class CreateInvoiceDTO
{
    /**
     * @param  array<int, array{description: string, quantity: float|int|string, unit_price_cents: int, vat_rate?: float|int|string, item_id?: int|null, discount_type?: string|null, discount_percent?: float|int|string|null, discount_cents?: int|string|null, income_account_id?: int|null}>  $lineItems
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
        public ?string $discountType = null,
        public float|int|string|null $discountPercent = null,
        public int|string|null $discountCents = null,
        public ?int $incomeAccountId = null,
    ) {}
}
