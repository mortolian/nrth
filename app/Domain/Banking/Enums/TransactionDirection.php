<?php

namespace App\Domain\Banking\Enums;

enum TransactionDirection: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
