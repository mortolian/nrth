<?php

namespace App\Domain\Banking\Support;

final class BankingMoney
{
    public static function toCents(string|int|float|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        return (int) round(((float) $amount) * 100);
    }
}
