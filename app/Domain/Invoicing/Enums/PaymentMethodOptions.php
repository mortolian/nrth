<?php

namespace App\Domain\Invoicing\Enums;

final class PaymentMethodOptions
{
    /**
     * @return list<array{value: string, label: string}>
     */
    public static function forInertia(): array
    {
        return array_map(
            fn (PaymentMethod $method) => [
                'value' => $method->value,
                'label' => match ($method) {
                    PaymentMethod::Eft => 'EFT',
                    PaymentMethod::Cash => 'Cash',
                    PaymentMethod::Card => 'Card',
                    PaymentMethod::Other => 'Other',
                },
            ],
            PaymentMethod::cases()
        );
    }
}
