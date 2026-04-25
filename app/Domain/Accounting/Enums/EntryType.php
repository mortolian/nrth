<?php

namespace App\Domain\Accounting\Enums;

enum EntryType: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    public function opposite(): self
    {
        return match ($this) {
            self::Debit => self::Credit,
            self::Credit => self::Debit,
        };
    }
}
