<?php

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SendInvoiceAction
{
    public function execute(Invoice $invoice): Invoice
    {
        if ($invoice->status === InvoiceStatus::Void) {
            throw ValidationException::withMessages([
                'status' => __('Cannot send a void invoice.'),
            ]);
        }

        return DB::transaction(function () use ($invoice): Invoice {
            $invoice->status = InvoiceStatus::Sent;
            $invoice->sent_at = now();
            $invoice->save();

            // Placeholder for queued mail/PDF flow until template + mailable are introduced.
            Log::info('Invoice queued for delivery', [
                'invoice_id' => $invoice->id,
                'team_id' => $invoice->team_id,
                'client_id' => $invoice->client_id,
            ]);

            if (function_exists('activity')) {
                activity()
                    ->performedOn($invoice)
                    ->withProperties(['status' => InvoiceStatus::Sent->value])
                    ->log('invoice_sent');
            }

            return $invoice->refresh();
        });
    }
}
