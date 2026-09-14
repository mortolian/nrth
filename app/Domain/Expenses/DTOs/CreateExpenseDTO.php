<?php

namespace App\Domain\Expenses\DTOs;

readonly class CreateExpenseDTO
{
    public function __construct(
        public int $teamId,
        public ?int $createdBy,
        public string $date,
        public ?int $supplierId,
        public ?string $supplierName,
        public int $categoryAccountId,
        public ?string $description,
        public int $amountExclVatCents,
        public string $vatRate,
        public int $vatAmountCents,
        public int $paidFromBankingAccountId,
        public ?string $reference = null,
        public ?string $notes = null,
        public ?float $officePercentage = null,
        public ?float $distanceKm = null,
        public ?float $ratePerKm = null,
        public ?int $recurringExpenseId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromValidated(int $teamId, ?int $createdBy, array $payload, ?int $recurringExpenseId = null): self
    {
        $supplierId = isset($payload['supplier_id']) ? (int) $payload['supplier_id'] : 0;

        return new self(
            teamId: $teamId,
            createdBy: $createdBy,
            date: (string) $payload['date'],
            supplierId: $supplierId > 0 ? $supplierId : null,
            supplierName: isset($payload['supplier']) ? trim((string) $payload['supplier']) : null,
            categoryAccountId: (int) $payload['category_account_id'],
            description: isset($payload['description']) ? (string) $payload['description'] : null,
            amountExclVatCents: (int) $payload['amount_excl_vat_cents'],
            vatRate: (string) $payload['vat_rate'],
            vatAmountCents: (int) $payload['vat_amount_cents'],
            paidFromBankingAccountId: (int) $payload['paid_from_banking_account_id'],
            reference: isset($payload['reference']) ? (string) $payload['reference'] : null,
            notes: isset($payload['notes']) ? (string) $payload['notes'] : null,
            officePercentage: isset($payload['office_percentage']) ? (float) $payload['office_percentage'] : null,
            distanceKm: isset($payload['distance_km']) ? (float) $payload['distance_km'] : null,
            ratePerKm: isset($payload['rate_per_km']) ? (float) $payload['rate_per_km'] : null,
            recurringExpenseId: $recurringExpenseId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'date' => $this->date,
            'supplier_id' => $this->supplierId,
            'supplier' => $this->supplierName,
            'category_account_id' => $this->categoryAccountId,
            'description' => $this->description,
            'amount_excl_vat_cents' => $this->amountExclVatCents,
            'vat_rate' => $this->vatRate,
            'vat_amount_cents' => $this->vatAmountCents,
            'paid_from_banking_account_id' => $this->paidFromBankingAccountId,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'office_percentage' => $this->officePercentage,
            'distance_km' => $this->distanceKm,
            'rate_per_km' => $this->ratePerKm,
        ];
    }
}
