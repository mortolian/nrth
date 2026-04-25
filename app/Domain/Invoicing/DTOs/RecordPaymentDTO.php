<?php

namespace App\Domain\Invoicing\DTOs;

use App\Domain\Invoicing\Enums\PaymentMethod;

readonly class RecordPaymentDTO
{
    public function __construct(
        public int $invoiceId,
        public int $teamId,
        public int $amountCents,
        public string $paymentDate,
        public PaymentMethod $method = PaymentMethod::Eft,
        public string $currency = 'ZAR',
        public ?string $reference = null,
        public ?string $notes = null,
        public ?int $createdBy = null,
    ) {}
}
