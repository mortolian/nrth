<?php

namespace App\Modules\Wealth\Enums;

enum WealthLiquidity: string
{
    case ImmediatelyAvailable = 'immediately_available';
    case Accessible = 'accessible';
    case Restricted = 'restricted';
    case Retirement = 'retirement';

    public function label(): string
    {
        return match ($this) {
            self::ImmediatelyAvailable => 'Immediately available',
            self::Accessible => 'Accessible',
            self::Restricted => 'Restricted',
            self::Retirement => 'Retirement',
        };
    }

    public function isAccessible(): bool
    {
        return match ($this) {
            self::ImmediatelyAvailable, self::Accessible => true,
            self::Restricted, self::Retirement => false,
        };
    }

    public function isRestrictedOrRetirement(): bool
    {
        return ! $this->isAccessible();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases()
        );
    }
}
