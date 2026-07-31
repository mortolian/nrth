<?php

namespace App\Domain\Accounting\DTOs;

final class ParsedSupplierDocument
{
    /**
     * @param  array{street: string|null, city: string|null, province: string|null, postal_code: string|null, country: string|null}|null  $address
     */
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $contactName,
        public readonly ?string $email,
        public readonly ?string $phone,
        public readonly ?string $vatNumber,
        public readonly ?string $registrationNumber,
        public readonly ?array $address,
        public readonly ?string $notes,
        public readonly float $confidence,
    ) {}

    /**
     * @return array{
     *     name: string|null,
     *     contact_name: string|null,
     *     email: string|null,
     *     phone: string|null,
     *     vat_number: string|null,
     *     registration_number: string|null,
     *     address: array{street: string|null, city: string|null, province: string|null, postal_code: string|null, country: string|null}|null,
     *     notes: string|null,
     *     confidence: float
     * }
     */
    public function toFormPayload(): array
    {
        return [
            'name' => $this->name,
            'contact_name' => $this->contactName,
            'email' => $this->email,
            'phone' => $this->phone,
            'vat_number' => $this->vatNumber,
            'registration_number' => $this->registrationNumber,
            'address' => $this->address,
            'notes' => $this->notes,
            'confidence' => $this->confidence,
        ];
    }
}
