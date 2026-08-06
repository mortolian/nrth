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
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

class SendInvoiceAction
{
    public function __construct(
        private readonly InvoicePdfService $invoicePdfService,
        private readonly PostInvoiceAccrualAction $postInvoiceAccrualAction,
    ) {}

    public function execute(Invoice $invoice): Invoice
    {
        if ($invoice->status === InvoiceStatus::Void) {
            throw ValidationException::withMessages([
                'status' => __('Cannot send a void invoice.'),
            ]);
        }

        $invoice->loadMissing(['client', 'team', 'lineItems']);

        $email = trim((string) ($invoice->client?->email ?? ''));
        if ($email === '') {
            throw ValidationException::withMessages([
                'email' => __('Add an email address on the client before sending this invoice.'),
            ]);
        }

        $pdfMedia = $this->safelyGeneratePdf($invoice);
        $wasDraft = $invoice->status === InvoiceStatus::Draft;

        return DB::transaction(function () use ($invoice, $pdfMedia, $email, $wasDraft): Invoice {
            if ($wasDraft) {
                $invoice->status = InvoiceStatus::Sent;
                $invoice->sent_at = now();
                $invoice->save();
                $this->postInvoiceAccrualAction->execute($invoice->fresh());
            } elseif ($invoice->sent_at === null) {
                $invoice->sent_at = now();
                $invoice->save();
            }

            $fresh = $invoice->fresh(['client', 'team']);

            DB::afterCommit(function () use ($fresh, $pdfMedia, $email): void {
                Mail::to($email)->queue(new InvoiceMailer($fresh, $pdfMedia?->id));
            });

            if (function_exists('activity')) {
                activity()
                    ->performedOn($invoice)
                    ->withProperties([
                        'status' => $invoice->status->value,
                        'resent' => ! $wasDraft,
                    ])
                    ->log($wasDraft ? 'invoice_sent' : 'invoice_resent');
            }

            Log::info('Invoice queued for delivery', [
                'invoice_id' => $invoice->id,
                'team_id' => $invoice->team_id,
                'client_id' => $invoice->client_id,
                'client_email' => $email,
                'pdf_media_id' => $pdfMedia?->id,
                'pdf_attached' => $pdfMedia !== null,
                'resent' => ! $wasDraft,
            ]);

            return $invoice->refresh();
        });
    }

    private function safelyGeneratePdf(Invoice $invoice): ?Media
    {
        try {
            return $this->invoicePdfService->generate($invoice);
        } catch (Throwable $e) {
            Log::warning('Invoice PDF generation failed; sending without attachment', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
