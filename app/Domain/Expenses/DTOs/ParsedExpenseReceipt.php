<?php

namespace App\Domain\Expenses\DTOs;

final class ParsedExpenseReceipt
{
    /**
     * @param  'vat15'|'vat0'|'exempt'|'no_vat'|null  $vatRate
     */
    public function __construct(
        public readonly ?string $date,
        public readonly ?string $supplierName,
        public readonly ?int $supplierId,
        public readonly ?string $description,
        public readonly ?float $amountExclVat,
        public readonly ?float $vatAmount,
        public readonly ?string $vatRate,
        public readonly ?string $reference,
        public readonly float $confidence,
    ) {}

    /**
     * @return array{
     *     date: string|null,
     *     supplier_id: int,
     *     supplier: string,
     *     description: string,
     *     amount_excl_vat: float|null,
     *     vat_amount: float|null,
     *     vat_rate: string|null,
     *     reference: string,
     *     confidence: float
     * }
     */
    public function toFormPayload(): array
    {
        return [
            'date' => $this->date,
            'supplier_id' => $this->supplierId ?? 0,
            'supplier' => $this->supplierId ? '' : (string) ($this->supplierName ?? ''),
            'description' => (string) ($this->description ?? ''),
            'amount_excl_vat' => $this->amountExclVat,
            'vat_amount' => $this->vatAmount,
            'vat_rate' => $this->vatRate,
            'reference' => (string) ($this->reference ?? ''),
            'confidence' => $this->confidence,
        ];
    }
}
