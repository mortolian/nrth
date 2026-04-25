<?php

namespace App\Domain\Accounting\Enums;

enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';

    public function normalBalance(): string
    {
        return match ($this) {
            self::Asset, self::Expense => 'debit',
            self::Liability, self::Equity, self::Income => 'credit',
        };
    }

    public function isDebit(): bool
    {
        return $this->normalBalance() === 'debit';
    }

    public function isCredit(): bool
    {
        return $this->normalBalance() === 'credit';
    }
}
