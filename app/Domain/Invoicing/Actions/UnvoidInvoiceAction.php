<?php

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UnvoidInvoiceAction
{
    public function execute(Invoice $invoice): Invoice
    {
        if ($invoice->status !== InvoiceStatus::Void) {
            throw ValidationException::withMessages([
                'status' => __('Only void invoices can be restored.'),
            ]);
        }

        return DB::transaction(function () use ($invoice): Invoice {
            $invoice->status = $invoice->sent_at ? InvoiceStatus::Sent : InvoiceStatus::Draft;
            $invoice->voided_at = null;
            $invoice->save();

            return $invoice->refresh();
        });
    }
}

