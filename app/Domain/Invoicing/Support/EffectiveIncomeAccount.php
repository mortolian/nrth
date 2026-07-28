<?php

namespace App\Domain\Invoicing\Support;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceLineItem;

final class EffectiveIncomeAccount
{
    public static function id(Invoice $invoice, InvoiceLineItem $line): ?int
    {
        return $line->income_account_id ?? $invoice->income_account_id;
    }
}
