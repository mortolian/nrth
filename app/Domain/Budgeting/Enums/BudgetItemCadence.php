<?php

namespace App\Domain\Budgeting\Enums;

enum BudgetItemCadence: string
{
    case Monthly = 'monthly';
    case OncePerPeriod = 'once_per_period';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
