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

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Invoice',
            self::Payment => 'Payment',
            self::Expense => 'Expense',
            self::Transfer => 'Transfer',
            self::JournalAdjustment => 'Journal adjustment',
            self::OpeningBalance => 'Opening balance',
        };
    }

    /**
     * Short explanation of what this journal adjustment is for (null for other types).
     */
    public function purposeDescription(?string $reference, ?string $description): ?string
    {
        if ($this !== self::JournalAdjustment) {
            return null;
        }

        if (str_starts_with((string) $reference, 'VOID-')) {
            return 'Reverses a voided transaction so its ledger impact is undone.';
        }

        if (str_starts_with((string) $description, 'Invoice accrual')) {
            return 'Records accounts receivable, revenue, and VAT when an invoice is issued.';
        }

        return 'Moves amounts between accounts without recording a payment, expense, or transfer.';
    }
}
