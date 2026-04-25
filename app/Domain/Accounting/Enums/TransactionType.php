<?php

namespace App\Domain\Accounting\Enums;

enum TransactionType: string
{
    case Invoice = 'invoice';
    case Payment = 'payment';
    case Expense = 'expense';
    case Transfer = 'transfer';
    case JournalAdjustment = 'journal_adjustment';
    case OpeningBalance = 'opening_balance';
}
