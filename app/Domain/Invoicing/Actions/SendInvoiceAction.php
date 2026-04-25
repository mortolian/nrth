<?php

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Services\InvoicePdfService;
use App\Mail\InvoiceMailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class SendInvoiceAction
{
    public function __construct(
        private readonly InvoicePdfService $invoicePdfService,
    ) {}

    public function execute(Invoice $invoice): Invoice
    {
        if ($invoice->status === InvoiceStatus::Void) {
            throw ValidationException::withMessages([
                'status' => __('Cannot send a void invoice.'),
            ]);
        }

        return DB::transaction(function () use ($invoice): Invoice {
            $invoice->loadMissing(['client', 'team', 'lineItems']);
            $pdfMedia = $this->invoicePdfService->generate($invoice);

            $invoice->status = InvoiceStatus::Sent;
            $invoice->sent_at = now();
            $invoice->save();

            if (! empty($invoice->client?->email)) {
                Mail::to($invoice->client->email)->queue(new InvoiceMailer($invoice->fresh(), $pdfMedia));
            }

            Log::info('Invoice queued for delivery', [
                'invoice_id' => $invoice->id,
                'team_id' => $invoice->team_id,
                'client_id' => $invoice->client_id,
                'pdf_media_id' => $pdfMedia->id,
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
