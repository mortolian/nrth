<?php

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarkInvoiceSentAction
{
    public function execute(Invoice $invoice): Invoice
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => __('Only draft invoices can be marked as sent.'),
            ]);
        }

        return DB::transaction(function () use ($invoice): Invoice {
            $invoice->status = InvoiceStatus::Sent;
            $invoice->sent_at = now();
            $invoice->save();

            if (function_exists('activity')) {
                activity()
                    ->performedOn($invoice)
                    ->withProperties(['status' => InvoiceStatus::Sent->value, 'manual' => true])
                    ->log('invoice_marked_sent');
            }

            return $invoice->refresh();
        });
    }
}
